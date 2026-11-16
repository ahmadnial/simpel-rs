<?php

namespace Tests\Feature;

use App\Contracts\EvidenceSigner;
use App\Contracts\ImmutableEvidenceStore;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\SignatureEvidence;
use App\Models\SigningCeremony;
use App\Models\SigningOutboxMessage;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentPdfService;
use App\Services\DocumentService;
use App\Services\UnavailableEvidenceSigner;
use App\Services\UnavailableImmutableEvidenceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RequestsSigningOtp;
use Tests\TestCase;

class SigningCeremonyStateMachineTest extends TestCase
{
    use RefreshDatabase;
    use RequestsSigningOtp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web']);
    }

    public function test_render_failure_never_publishes_signed_or_partial_evidence_state(): void
    {
        $fixture = $this->fixture();
        $pdf = new ControllableDocumentPdfService(Storage::disk('local')->path('fixtures/candidate.pdf'));
        $pdf->throwOnRender = true;
        app()->instance(DocumentPdfService::class, $pdf);
        $this->actingAs($fixture['signer']);

        try {
            app(DocumentService::class)->prepareOtpContext($fixture['document'], $fixture['signer'], 'render-failure-session');
            $this->fail('Render failure harus diteruskan.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected_render_failure', $exception->getMessage());
        }

        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertNull($fixture['document']->fresh()->nomor_surat);
        $this->assertDatabaseCount('document_signatures', 0);
        $this->assertDatabaseCount('signature_evidence', 0);
        $this->assertSame(SigningCeremony::STATE_FAILED, SigningCeremony::firstOrFail()->state);
    }

    public function test_missing_source_document_fails_closed_without_generating_replacement_content(): void
    {
        $fixture = $this->fixture();
        $source = $fixture['document']->currentVersion->file_path;
        Storage::disk('local')->delete($source);
        $this->actingAs($fixture['signer']);

        try {
            app(DocumentService::class)->prepareOtpContext($fixture['document'], $fixture['signer'], 'missing-source-session');
            $this->fail('Berkas sumber yang hilang harus menghentikan ceremony.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertFalse(Storage::disk('local')->exists($source));
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertSame(SigningCeremony::STATE_FAILED, SigningCeremony::firstOrFail()->state);
        $this->assertDatabaseCount('document_signatures', 0);
    }

    public function test_corrupt_source_document_fails_closed_before_candidate_pdf_is_created(): void
    {
        $fixture = $this->fixture();
        Storage::disk('local')->put($fixture['document']->currentVersion->file_path, 'corrupt-docx-bytes');
        $this->actingAs($fixture['signer']);

        try {
            app(DocumentService::class)->prepareOtpContext($fixture['document'], $fixture['signer'], 'corrupt-source-session');
            $this->fail('DOCX rusak harus menghentikan ceremony.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file_dokumen', $exception->errors());
        }

        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertSame(SigningCeremony::STATE_FAILED, SigningCeremony::firstOrFail()->state);
        $this->assertDatabaseCount('document_signatures', 0);
        $this->assertDatabaseCount('signature_evidence', 0);
    }

    public function test_failed_ceremony_is_reused_for_the_same_idempotency_lineage(): void
    {
        Storage::disk('local')->put('fixtures/candidate.pdf', "%PDF-1.4\nfixed candidate bytes");
        $fixture = $this->fixture();
        $pdf = new ControllableDocumentPdfService(Storage::disk('local')->path('fixtures/candidate.pdf'));
        $pdf->throwOnRender = true;
        app()->instance(DocumentPdfService::class, $pdf);
        $this->actingAs($fixture['signer']);
        $service = app(DocumentService::class);

        try {
            $service->prepareOtpContext($fixture['document'], $fixture['signer'], 'retry-same-session');
            $this->fail('Render failure harus diteruskan.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected_render_failure', $exception->getMessage());
        }

        $failed = SigningCeremony::firstOrFail();
        $pdf->throwOnRender = false;
        $context = $service->prepareOtpContext($fixture['document'], $fixture['signer'], 'retry-same-session');

        $this->assertSame($failed->uuid, $context['signing_ceremony_id']);
        $this->assertDatabaseCount('signing_ceremonies', 1);
        $this->assertSame(SigningCeremony::STATE_AWAITING_USER_SIGNATURE, $failed->fresh()->state);
    }

    public function test_storage_failure_leaves_reconcilable_user_signed_state_and_retry_is_idempotent(): void
    {
        Storage::disk('local')->put('fixtures/candidate.pdf', "%PDF-1.4\nfixed candidate bytes");
        $fixture = $this->fixture();
        $fixture['signer']->update(['otp_email' => 'otp-director@example.test']);
        $pdf = new ControllableDocumentPdfService(Storage::disk('local')->path('fixtures/candidate.pdf'));
        $pdf->failPersistOnce = true;
        app()->instance(DocumentPdfService::class, $pdf);
        $this->actingAs($fixture['signer']);
        $service = app(DocumentService::class);
        $otp = $this->requestSigningOtp($fixture['signer'], $fixture['document'], 'storage-failure-session');

        try {
            $service->tandaTangani($fixture['document'], $otp['otp'], $otp['session_id']);
            $this->fail('Storage failure harus menghentikan finalisasi.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected_storage_failure', $exception->getMessage());
        }

        $ceremony = SigningCeremony::firstOrFail();
        $this->assertSame(SigningCeremony::STATE_USER_SIGNED, $ceremony->state);
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertDatabaseCount('document_signatures', 0);
        $this->assertDatabaseCount('signature_evidence', 0);
        $this->assertSame('pending', SigningOutboxMessage::firstOrFail()->state);

        $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->get(route('ttd.show', $fixture['document']))
            ->assertOk()
            ->assertSee('OTP sudah diverifikasi')
            ->assertSee('Persetujuan Anda telah tercatat')
            ->assertDontSee('id="form-tte"', false)
            ->assertDontSee('Kembalikan Dokumen ke Verifikator');

        $this->postJson(route('ttd.kirim-otp', $fixture['document']))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'OTP sudah diverifikasi dan persetujuan Anda telah tercatat. Finalisasi sedang menunggu pemulihan layanan; jangan meminta OTP baru.']);

        $this->artisan('tte:process-signing-outbox')->assertSuccessful();
        $sealed = $fixture['document']->fresh();
        $this->assertSame(Document::STATUS_DITANDATANGANI, $sealed->status);
        $this->assertSame(SigningCeremony::STATE_SEALED, $ceremony->fresh()->state);
        $this->assertDatabaseCount('document_signatures', 1);
        $this->assertDatabaseCount('signature_evidence', 1);
        $this->assertSame('processed', SigningOutboxMessage::firstOrFail()->state);
        $this->assertSame('ot**********@example.test', SignatureEvidence::firstOrFail()->signer_snapshot['email_destination_masked']);

        $again = $service->resumeFinalization($ceremony);
        $this->assertSame($sealed->id, $again->id);
        $this->assertSame(1, DocumentSignature::count());
        $this->assertSame(1, SignatureEvidence::count());
        $this->assertNotSame(str_repeat('0', 64), DocumentSignature::firstOrFail()->hash_dokumen);
    }

    public function test_signing_page_requires_reauthentication_then_exposes_exact_candidate_pdf_and_hash(): void
    {
        app()->instance(DocumentPdfService::class, new \Tests\Support\FakeDocumentPdfService);
        $fixture = $this->fixture();

        $this->actingAs($fixture['signer'])
            ->get(route('ttd.show', $fixture['document']))
            ->assertOk()
            ->assertSee('Konfirmasi ulang password diperlukan');

        $response = $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->get(route('ttd.show', $fixture['document']))
            ->assertOk();
        $ceremony = SigningCeremony::firstOrFail();
        $response->assertSee($ceremony->candidate_pdf_hash);
        $response->assertSee(route('ttd.candidate', [$fixture['document'], $ceremony]), false);

        $this->get(route('ttd.candidate', [$fixture['document'], $ceremony]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Document-SHA256', $ceremony->candidate_pdf_hash);
    }

    public function test_unavailable_signing_infrastructure_blocks_otp_before_a_ceremony_is_created(): void
    {
        $fixture = $this->fixture();
        app()->instance(EvidenceSigner::class, new UnavailableEvidenceSigner);

        $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->postJson(route('ttd.kirim-otp', $fixture['document']))
            ->assertStatus(503)
            ->assertJsonFragment([
                'message' => 'Layanan finalisasi pengesahan sedang tidak tersedia. OTP belum dikirim atau dikonsumsi. Hubungi administrator untuk memulihkan layanan KMS dan penyimpanan bukti.',
            ]);

        $this->assertDatabaseCount('signing_ceremonies', 0);
        $this->assertDatabaseCount('signature_otp_challenges', 0);
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
    }

    public function test_preview_remains_available_during_kms_outage_and_is_reused_when_kms_recovers(): void
    {
        $this->assertPreviewSurvivesInfrastructureOutage(EvidenceSigner::class, new UnavailableEvidenceSigner);
    }

    public function test_preview_remains_available_during_worm_outage_and_is_reused_when_worm_recovers(): void
    {
        $this->assertPreviewSurvivesInfrastructureOutage(ImmutableEvidenceStore::class, new UnavailableImmutableEvidenceStore);
    }

    private function assertPreviewSurvivesInfrastructureOutage(string $contract, object $unavailableProvider): void
    {
        Notification::fake();
        $bytes = "%PDF-1.4\nreviewed candidate bytes\n%%EOF";
        Storage::disk('local')->put('fixtures/reviewed.pdf', $bytes);
        $pdf = \Mockery::mock(DocumentPdfService::class);
        $pdf->shouldReceive('render')->once()->andReturn(Storage::disk('local')->path('fixtures/reviewed.pdf'));
        app()->instance(DocumentPdfService::class, $pdf);
        $fixture = $this->fixture();
        $availableProvider = app($contract);
        app()->instance($contract, $unavailableProvider);

        $response = $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->get(route('ttd.show', $fixture['document']))
            ->assertOk()
            ->assertDontSee('Layanan pengesahan belum siap');
        // Pertahankan cookie sesi seperti browser agar lineage preview/OTP sama.
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $ceremony = SigningCeremony::firstOrFail();
        $candidateUrl = route('ttd.candidate', [$fixture['document'], $ceremony]);
        $response->assertSee($candidateUrl, false)->assertSee(hash('sha256', $bytes));
        $preview = $this->get($candidateUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Document-SHA256', hash('sha256', $bytes));
        $this->assertSame($bytes, file_get_contents($preview->baseResponse->getFile()->getPathname()));
        $this->assertDatabaseCount('signature_otp_challenges', 0);

        $this->postJson(route('ttd.kirim-otp', $fixture['document']))->assertStatus(503);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('signature_otp_challenges', 0);
        $this->assertSame(SigningCeremony::STATE_AWAITING_USER_SIGNATURE, $ceremony->fresh()->state);
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertDatabaseCount('document_signatures', 0);
        $this->assertDatabaseCount('signature_evidence', 0);
        $this->assertDatabaseCount('signing_outbox_messages', 0);

        $this->get(route('ttd.show', $fixture['document']))->assertOk()->assertSee($candidateUrl, false);
        app()->instance($contract, $availableProvider);
        // Resolusi dependency ulang untuk menyimulasikan request setelah layanan pulih.
        app('router')->getRoutes()->getByName('ttd.kirim-otp')->flushController();
        $this->postJson(route('ttd.kirim-otp', $fixture['document']))->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseCount('signing_ceremonies', 1);
        $this->assertDatabaseHas('signature_otp_challenges', [
            'signing_ceremony_id' => $ceremony->uuid,
            'pdf_hash' => hash('sha256', $bytes),
        ]);
        $this->assertSame($bytes, Storage::disk('local')->get($ceremony->fresh()->candidate_pdf_path));
    }

    public function test_missing_production_signer_fails_before_otp_is_consumed(): void
    {
        Storage::disk('local')->put('fixtures/candidate.pdf', "%PDF-1.4\nfixed candidate bytes");
        $fixture = $this->fixture();
        $pdf = new ControllableDocumentPdfService(Storage::disk('local')->path('fixtures/candidate.pdf'));
        app()->instance(DocumentPdfService::class, $pdf);
        $this->actingAs($fixture['signer']);
        $otp = $this->requestSigningOtp($fixture['signer'], $fixture['document'], 'missing-kms-session');
        app()->instance(EvidenceSigner::class, new UnavailableEvidenceSigner);

        try {
            app(DocumentService::class)->tandaTangani($fixture['document'], $otp['otp'], $otp['session_id']);
            $this->fail('Tanpa provider KMS, OTP tidak boleh dikonsumsi.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('OTP belum dikirim atau dikonsumsi', $exception->getMessage());
        }

        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertSame(SigningCeremony::STATE_AWAITING_USER_SIGNATURE, SigningCeremony::firstOrFail()->state);
        $this->assertDatabaseCount('signing_outbox_messages', 0);
        $this->assertDatabaseCount('signature_evidence', 0);
        $this->assertDatabaseCount('document_signatures', 0);
    }

    public function test_missing_worm_provider_fails_before_otp_is_consumed(): void
    {
        Storage::disk('local')->put('fixtures/candidate.pdf', "%PDF-1.4\nfixed candidate bytes");
        $fixture = $this->fixture();
        app()->instance(DocumentPdfService::class, new ControllableDocumentPdfService(Storage::disk('local')->path('fixtures/candidate.pdf')));
        $this->actingAs($fixture['signer']);
        $otp = $this->requestSigningOtp($fixture['signer'], $fixture['document'], 'missing-worm-session');
        app()->instance(ImmutableEvidenceStore::class, new UnavailableImmutableEvidenceStore);

        try {
            app(DocumentService::class)->tandaTangani($fixture['document'], $otp['otp'], $otp['session_id']);
            $this->fail('Tanpa provider WORM, OTP tidak boleh dikonsumsi.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('OTP belum dikirim atau dikonsumsi', $exception->getMessage());
        }

        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertSame(SigningCeremony::STATE_AWAITING_USER_SIGNATURE, SigningCeremony::firstOrFail()->state);
        $this->assertDatabaseCount('signing_outbox_messages', 0);
        $this->assertDatabaseCount('signature_evidence', 0);
        $this->assertDatabaseCount('document_signatures', 0);
    }

    /** @return array{signer:User,document:Document} */
    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $unit = Unit::create(['nama' => "Unit {$suffix}", 'kode' => "U{$suffix}", 'urutan' => 1]);
        $proposer = $this->user('Proposer', "proposer-{$suffix}@example.test", $unit);
        $signer = $this->user('Signer', "signer-{$suffix}@example.test", $unit);
        $signer->assignRole('penandatangan');
        $type = DocumentType::create([
            'nama' => "Type {$suffix}", 'kode' => "T{$suffix}", 'singkatan' => 'TTE',
            'format_nomor' => "{urut}/TTE/{$suffix}/{tahun}", 'is_active' => true, 'urutan' => 1,
        ]);
        $workflow = WorkflowTemplate::create([
            'nama' => "Workflow {$suffix}", 'document_type_id' => $type->id, 'is_default' => true, 'is_active' => true,
        ]);
        WorkflowStep::create([
            'workflow_template_id' => $workflow->id, 'urutan' => 1, 'nama_tahap' => 'Signer',
            'tipe' => 'penandatangan', 'role_nama' => 'penandatangan', 'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2,
        ]);
        $document = Document::create([
            'judul' => "Document {$suffix}", 'document_type_id' => $type->id, 'unit_id' => $unit->id,
            'pengusul_id' => $proposer->id, 'workflow_template_id' => $workflow->id,
            'status' => Document::STATUS_MENUNGGU_TTD, 'current_step' => 1,
        ]);
        DocumentVersion::create([
            'document_id' => $document->id, 'versi' => 1, 'file_path' => "documents/{$document->id}/state-machine.docx",
            'file_name' => 'state-machine.docx', 'uploaded_by' => $proposer->id, 'is_current' => true,
        ]);
        Storage::disk('local')->makeDirectory("documents/{$document->id}");
        app(DocumentService::class)->createDocxFileFromHtml(
            '<p>Naskah pengujian state machine.</p>',
            Storage::disk('local')->path("documents/{$document->id}/state-machine.docx"),
        );

        return compact('signer') + ['document' => $document->fresh(['currentVersion', 'workflowTemplate'])];
    }

    private function user(string $name, string $email, Unit $unit): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'unit_id' => $unit->id, 'is_active' => true,
        ]);
    }
}

class ControllableDocumentPdfService extends DocumentPdfService
{
    public bool $throwOnRender = false;

    public bool $failPersistOnce = false;

    public function __construct(private readonly string $candidatePath) {}

    public function render(string $docxPath, Document $document, DocumentVersion $version): string
    {
        if ($this->throwOnRender) {
            throw new RuntimeException('injected_render_failure');
        }

        return $this->candidatePath;
    }

    public function persistOfficial(Document $document, DocumentVersion $version, string $renderedPdfPath, string $token): array
    {
        if ($this->failPersistOnce) {
            $this->failPersistOnce = false;
            throw new RuntimeException('injected_storage_failure');
        }
        $path = "documents/{$document->id}/signed/{$token}.pdf";
        Storage::disk('local')->put($path, file_get_contents($renderedPdfPath));

        return ['path' => $path, 'hash' => hash_file('sha256', Storage::disk('local')->path($path)), 'size' => filesize(Storage::disk('local')->path($path))];
    }
}

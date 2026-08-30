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
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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

        $sealed = $service->resumeFinalization($ceremony);
        $this->assertSame(Document::STATUS_DITANDATANGANI, $sealed->status);
        $this->assertSame(SigningCeremony::STATE_SEALED, $ceremony->fresh()->state);
        $this->assertDatabaseCount('document_signatures', 1);
        $this->assertDatabaseCount('signature_evidence', 1);
        $this->assertSame('processed', SigningOutboxMessage::firstOrFail()->state);

        $again = $service->resumeFinalization($ceremony);
        $this->assertSame($sealed->id, $again->id);
        $this->assertSame(1, DocumentSignature::count());
        $this->assertSame(1, SignatureEvidence::count());
        $this->assertNotSame(str_repeat('0', 64), DocumentSignature::firstOrFail()->hash_dokumen);
    }

    public function test_signing_page_requires_reauthentication_then_exposes_exact_candidate_pdf_and_hash(): void
    {
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

    public function test_missing_production_signer_fails_closed_before_document_is_published(): void
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
            $this->fail('Tanpa provider KMS, finalisasi harus gagal tertutup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('KMS/HSM/Vault', $exception->getMessage());
        }

        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertSame(SigningCeremony::STATE_USER_SIGNED, SigningCeremony::firstOrFail()->state);
        $this->assertDatabaseCount('signature_evidence', 0);
        $this->assertDatabaseCount('document_signatures', 0);
    }

    public function test_missing_worm_provider_fails_closed_before_document_is_published(): void
    {
        Storage::disk('local')->put('fixtures/candidate.pdf', "%PDF-1.4\nfixed candidate bytes");
        $fixture = $this->fixture();
        app()->instance(DocumentPdfService::class, new ControllableDocumentPdfService(Storage::disk('local')->path('fixtures/candidate.pdf')));
        app()->instance(ImmutableEvidenceStore::class, new UnavailableImmutableEvidenceStore);
        $this->actingAs($fixture['signer']);
        $otp = $this->requestSigningOtp($fixture['signer'], $fixture['document'], 'missing-worm-session');

        try {
            app(DocumentService::class)->tandaTangani($fixture['document'], $otp['otp'], $otp['session_id']);
            $this->fail('Tanpa provider WORM, finalisasi harus gagal tertutup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('WORM evidence provider', $exception->getMessage());
        }

        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
        $this->assertSame(SigningCeremony::STATE_USER_SIGNED, SigningCeremony::firstOrFail()->state);
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

<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Notifications\OtpTandaTangan;
use App\Services\DocumentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TteBaselineCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web']);
    }

    public function test_otp_is_obtained_from_fake_notification_without_plaintext_database_storage_or_response_leak(): void
    {
        Notification::fake();
        config(['app.debug' => false]);
        $fixture = $this->signingFixture();

        $response = $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->postJson(route('ttd.kirim-otp', $fixture['document']));

        $response->assertOk()->assertJsonMissingPath('debug_otp');

        $otp = null;
        Notification::assertSentTo(
            $fixture['signer'],
            OtpTandaTangan::class,
            function (OtpTandaTangan $notification) use (&$otp): bool {
                $otp = $notification->otp;

                return true;
            }
        );

        $signer = $fixture['signer']->fresh();
        $this->assertMatchesRegularExpression('/^\d{8}$/', $otp);
        $this->assertNull($signer->otp_code);
        $this->assertNull($signer->otp_hash);
    }

    public function test_signer_authorization_accepts_active_delegation_and_rejects_unrelated_user(): void
    {
        $fixture = $this->signingFixture(false);
        $principal = $this->user('Principal', 'principal@example.test', $fixture['unit']);
        $principal->assignRole('penandatangan');
        $delegate = $fixture['signer'];
        $unrelated = $this->user('Unrelated', 'unrelated@example.test', $fixture['unit']);

        Delegation::create([
            'pejabat_id' => $principal->id,
            'delegasi_id' => $delegate->id,
            'tipe' => 'plt',
            'alasan' => 'Karakterisasi delegasi aktif',
            'berlaku_dari' => now()->subDay()->toDateString(),
            'berlaku_sampai' => now()->addDay()->toDateString(),
            'is_active' => true,
            'dibuat_oleh' => $principal->id,
        ]);

        app(DocumentService::class)->assertCanSign($fixture['document'], $delegate);
        $this->addToAssertionCount(1);

        try {
            app(DocumentService::class)->assertCanSign($fixture['document'], $unrelated);
            $this->fail('User tanpa role atau delegasi tidak boleh menandatangani.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_public_verify_recomputes_the_stored_pdf_hash_and_detects_tampering(): void
    {
        Storage::fake('local');
        $fixture = $this->signingFixture();
        $path = "documents/{$fixture['document']->id}/signed/baseline.pdf";
        $original = "%PDF-1.4\nbaseline signed bytes";
        Storage::disk('local')->put($path, $original);

        $signature = DocumentSignature::create([
            'document_id' => $fixture['document']->id,
            'document_version_id' => $fixture['document']->currentVersion->id,
            'penandatangan_id' => $fixture['signer']->id,
            'metode_tte' => 'internal',
            'hash_dokumen' => hash('sha256', $original),
            'qr_token' => 'baseline-public-verify-token',
            'file_signed_path' => $path,
            'ditandatangani_at' => now(),
        ]);

        $this->get(route('public.verify', $signature->qr_token))
            ->assertOk()
            ->assertViewHas('integrityValid', true)
            ->assertSee('Pengesahan Tercatat — Pemeriksaan Terbatas')
            ->assertDontSee('Pengesahan Elektronik Internal Terverifikasi');

        Storage::disk('local')->put($path, $original.'!');

        $this->get(route('public.verify', $signature->qr_token))
            ->assertOk()
            ->assertViewHas('integrityValid', false);
    }

    public function test_database_constraint_allows_only_one_signature_per_document(): void
    {
        $fixture = $this->signingFixture();
        $attributes = [
            'document_id' => $fixture['document']->id,
            'document_version_id' => $fixture['document']->currentVersion->id,
            'penandatangan_id' => $fixture['signer']->id,
            'metode_tte' => 'internal',
            'hash_dokumen' => str_repeat('a', 64),
            'file_signed_path' => 'documents/baseline.pdf',
            'ditandatangani_at' => now(),
        ];

        DocumentSignature::create($attributes + ['qr_token' => 'baseline-signature-one']);

        try {
            DocumentSignature::create($attributes + ['qr_token' => 'baseline-signature-two']);
            $this->fail('Unique constraint document_signatures.document_id tidak bekerja.');
        } catch (QueryException) {
            $this->assertSame(1, DocumentSignature::where('document_id', $fixture['document']->id)->count());
        }
    }

    /**
     * @return array{unit: Unit, signer: User, document: Document}
     */
    private function signingFixture(bool $assignSignerRole = true): array
    {
        $suffix = bin2hex(random_bytes(4));
        $unit = Unit::create(['nama' => "Unit {$suffix}", 'kode' => "U{$suffix}", 'urutan' => 1]);
        $proposer = $this->user('Proposer', "proposer-{$suffix}@example.test", $unit);
        $signer = $this->user('Signer', "signer-{$suffix}@example.test", $unit);
        if ($assignSignerRole) {
            $signer->assignRole('penandatangan');
        }

        $type = DocumentType::create([
            'nama' => "Type {$suffix}",
            'kode' => "T{$suffix}",
            'singkatan' => 'TTE',
            'format_nomor' => "{urut}/TTE/{$suffix}/{tahun}",
            'is_active' => true,
            'urutan' => 1,
        ]);
        $workflow = WorkflowTemplate::create([
            'nama' => "Workflow {$suffix}",
            'document_type_id' => $type->id,
            'is_default' => true,
            'is_active' => true,
        ]);
        WorkflowStep::create([
            'workflow_template_id' => $workflow->id,
            'urutan' => 1,
            'nama_tahap' => 'Penandatangan',
            'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan',
            'mode_verifikasi' => 'serial',
            'sla_hari_kerja' => 2,
        ]);
        $document = Document::create([
            'judul' => "Dokumen {$suffix}",
            'document_type_id' => $type->id,
            'unit_id' => $unit->id,
            'pengusul_id' => $proposer->id,
            'workflow_template_id' => $workflow->id,
            'status' => Document::STATUS_MENUNGGU_TTD,
            'current_step' => 1,
        ]);
        DocumentVersion::create([
            'document_id' => $document->id,
            'versi' => 1,
            'file_path' => "documents/{$document->id}/baseline.docx",
            'file_name' => 'baseline.docx',
            'uploaded_by' => $proposer->id,
            'is_current' => true,
        ]);

        return compact('unit', 'signer') + ['document' => $document->fresh('currentVersion')];
    }

    private function user(string $name, string $email, Unit $unit): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'unit_id' => $unit->id,
            'is_active' => true,
        ]);
    }
}

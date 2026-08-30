<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\SignatureEvidence;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\RequestsSigningOtp;
use Tests\TestCase;

class PublicPdfVerificationTest extends TestCase
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

    public function test_qr_lookup_does_not_claim_file_valid_until_user_uploads_exact_pdf(): void
    {
        [$signature, $evidence] = $this->signedEvidence();

        $this->get(route('public.verify', $signature->qr_token))
            ->assertOk()
            ->assertSee('PENGESAHAN SIMPEL-RS TERVERIFIKASI')
            ->assertSee('FILE PENGGUNA BELUM DIPERIKSA')
            ->assertDontSee('FILE RESMI — HASH COCOK')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $filesBefore = Storage::disk('local')->allFiles();
        $officialBytes = Storage::disk('local')->get($evidence->pdf_path);
        $upload = UploadedFile::fake()->createWithContent('official.pdf', $officialBytes);
        $this->post(route('public.verify.upload', $signature->qr_token), ['pdf' => $upload])
            ->assertOk()
            ->assertSee('PENGESAHAN SIMPEL-RS TERVERIFIKASI')
            ->assertSee('FILE RESMI — HASH COCOK')
            ->assertSee('Byte PDF yang diunggah cocok');
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles(), 'Verifier tidak boleh menyimpan file upload.');
    }

    public function test_public_document_page_finds_exact_official_pdf_without_qr_token(): void
    {
        [$signature, $evidence] = $this->signedEvidence();

        auth()->logout();
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('public.document.form'), false)
            ->assertSee('Validasi keaslian PDF');

        $this->get(route('public.document.form'))
            ->assertOk()
            ->assertSee('Validasi Dokumen SIMPEL-RS')
            ->assertHeader('Cache-Control', 'no-store, private');

        $filesBefore = Storage::disk('local')->allFiles();
        $upload = UploadedFile::fake()->createWithContent(
            'document-a.pdf',
            Storage::disk('local')->get($evidence->pdf_path)
        );

        $this->post(route('public.document.verify'), ['pdf' => $upload])
            ->assertOk()
            ->assertSee('PENGESAHAN SIMPEL-RS TERVERIFIKASI')
            ->assertSee('FILE RESMI — HASH COCOK')
            ->assertSee($signature->document->judul);
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles(), 'Validasi langsung tidak boleh menyimpan file upload.');
    }

    public function test_public_document_page_rejects_modified_or_unregistered_pdf_without_leaking_records(): void
    {
        [, $evidence] = $this->signedEvidence();
        $modified = UploadedFile::fake()->createWithContent(
            'document-a-edited.pdf',
            Storage::disk('local')->get($evidence->pdf_path).'edited'
        );

        $this->post(route('public.document.verify'), ['pdf' => $modified])
            ->assertOk()
            ->assertSee('DOKUMEN TIDAK COCOK / TIDAK TERDAFTAR')
            ->assertDontSee('PENGESAHAN SIMPEL-RS TERVERIFIKASI')
            ->assertDontSee('FILE RESMI — HASH COCOK');
    }

    public function test_modified_pdf_with_copied_qr_token_never_receives_file_valid_claim(): void
    {
        [$signature, $evidence] = $this->signedEvidence();
        $modified = UploadedFile::fake()->createWithContent(
            'copied-qr-modified.pdf',
            Storage::disk('local')->get($evidence->pdf_path).'!'
        );

        $this->post(route('public.verify.upload', $signature->qr_token), ['pdf' => $modified])
            ->assertOk()
            ->assertSee('PENGESAHAN SIMPEL-RS TERVERIFIKASI')
            ->assertSee('FILE TIDAK COCOK DENGAN DOKUMEN RESMI')
            ->assertDontSee('FILE RESMI — HASH COCOK');
    }

    public function test_oversized_non_pdf_and_active_content_are_rejected_safely(): void
    {
        [$signature] = $this->signedEvidence();

        $this->post(route('public.verify.upload', $signature->qr_token), [
            'pdf' => UploadedFile::fake()->create('oversized.pdf', 20481, 'application/pdf'),
        ])->assertSessionHasErrors('pdf');

        $this->post(route('public.verify.upload', $signature->qr_token), [
            'pdf' => UploadedFile::fake()->createWithContent('not-pdf.pdf', 'plain text'),
        ])->assertSessionHasErrors('pdf');

        $this->post(route('public.verify.upload', $signature->qr_token), [
            'pdf' => UploadedFile::fake()->createWithContent('active.pdf', "%PDF-1.4\n/OpenAction << /JS (alert) >>"),
        ])->assertSessionHasErrors('pdf');
    }

    /** @return array{DocumentSignature,SignatureEvidence} */
    private function signedEvidence(): array
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
            'document_id' => $document->id, 'versi' => 1, 'file_path' => "documents/{$document->id}/public-verify.docx",
            'file_name' => 'public-verify.docx', 'uploaded_by' => $proposer->id, 'is_current' => true,
        ]);
        $document = $document->fresh(['currentVersion', 'workflowTemplate']);
        $this->actingAs($signer);
        $otp = $this->requestSigningOtp($signer, $document, 'public-verifier-session');
        app(DocumentService::class)->tandaTangani($document, $otp['otp'], $otp['session_id']);

        return [
            DocumentSignature::where('document_id', $document->id)->firstOrFail(),
            SignatureEvidence::where('document_id', $document->id)->firstOrFail(),
        ];
    }

    private function user(string $name, string $email, Unit $unit): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'unit_id' => $unit->id, 'is_active' => true,
        ]);
    }
}

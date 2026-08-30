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
            ->assertSee('Pengesahan Elektronik Internal Terverifikasi')
            ->assertSee('Keaslian File Belum Diperiksa')
            ->assertDontSee('File PDF Sesuai Dokumen Resmi')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'")
            ->assertDontSee('PSrE')
            ->assertDontSee('BSrE');

        $filesBefore = Storage::disk('local')->allFiles();
        $officialBytes = Storage::disk('local')->get($evidence->pdf_path);
        $upload = UploadedFile::fake()->createWithContent('official.pdf', $officialBytes);
        $this->post(route('public.verify.upload', $signature->qr_token), ['pdf' => $upload])
            ->assertOk()
            ->assertSee('Pengesahan Elektronik Internal Terverifikasi')
            ->assertSee('File PDF Sesuai Dokumen Resmi')
            ->assertSee('File yang diunggah cocok');
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
            ->assertSee('Periksa keaslian PDF')
            ->assertSee('Validasi Dokumen Sekarang')
            ->assertHeader('Cache-Control', 'no-store, private');

        $filesBefore = Storage::disk('local')->allFiles();
        $upload = UploadedFile::fake()->createWithContent(
            'document-a.pdf',
            Storage::disk('local')->get($evidence->pdf_path)
        );

        $this->post(route('public.document.verify'), ['pdf' => $upload])
            ->assertOk()
            ->assertSee('Pengesahan Elektronik Internal Terverifikasi')
            ->assertSee('File PDF Sesuai Dokumen Resmi')
            ->assertSee($signature->document->judul);
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles(), 'Validasi langsung tidak boleh menyimpan file upload.');
    }

    public function test_public_document_page_rejects_modified_or_unregistered_pdf_without_leaking_records(): void
    {
        [$signature, $evidence] = $this->signedEvidence();
        $modified = UploadedFile::fake()->createWithContent(
            'document-a-edited.pdf',
            Storage::disk('local')->get($evidence->pdf_path).'edited'
        );

        $this->post(route('public.document.verify'), ['pdf' => $modified])
            ->assertOk()
            ->assertSee('Dokumen Tidak Cocok atau Tidak Terdaftar')
            ->assertDontSee('Pengesahan Elektronik Internal Terverifikasi')
            ->assertDontSee('File PDF Sesuai Dokumen Resmi')
            ->assertDontSee($signature->document->judul)
            ->assertDontSee($signature->penandatangan->name)
            ->assertDontSee($signature->hash_dokumen);
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
            ->assertSee('Pengesahan Elektronik Internal Terverifikasi')
            ->assertSee('File PDF Tidak Cocok')
            ->assertDontSee('File PDF Sesuai Dokumen Resmi');
    }

    public function test_oversized_and_non_pdf_are_rejected_while_pdf_is_treated_as_opaque_bytes(): void
    {
        [$signature] = $this->signedEvidence();

        $this->post(route('public.verify.upload', $signature->qr_token), [
            'pdf' => UploadedFile::fake()->create('oversized.pdf', 20481, 'application/pdf'),
        ])->assertSessionHasErrors('pdf');

        $this->post(route('public.verify.upload', $signature->qr_token), [
            'pdf' => UploadedFile::fake()->createWithContent('not-pdf.pdf', 'plain text'),
        ])->assertSessionHasErrors('pdf');

        // Verifier tidak merender atau mengeksekusi PDF. Token seperti /OpenAction
        // diperlakukan sebagai byte biasa dan hasil tetap fail-closed lewat hash.
        $this->post(route('public.verify.upload', $signature->qr_token), [
            'pdf' => UploadedFile::fake()->createWithContent('active.pdf', "%PDF-1.4\n/OpenAction << /JS (alert) >>"),
        ])->assertOk()
            ->assertSee('File PDF Tidak Cocok')
            ->assertDontSee('File PDF Sesuai Dokumen Resmi');
    }

    public function test_public_lookup_uses_generic_response_and_rate_limits_token_enumeration(): void
    {
        $unknownToken = '00000000-0000-4000-8000-000000000000';

        $response = $this->get(route('public.verify', $unknownToken));
        $response->assertOk()
            ->assertSee('Data Pengesahan Tidak Ditemukan')
            ->assertDontSee('SQL')
            ->assertDontSee('Exception');

        for ($attempt = 1; $attempt < 30; $attempt++) {
            $this->get(route('public.verify', $unknownToken))->assertOk();
        }

        $this->get(route('public.verify', $unknownToken))
            ->assertTooManyRequests()
            ->assertSee('Permintaan Terlalu Sering')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_public_upload_is_rate_limited_and_bundle_is_not_public(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('public.document.verify'))->assertSessionHasErrors('pdf');
        }

        $this->post(route('public.document.verify'))
            ->assertTooManyRequests()
            ->assertSee('Permintaan Terlalu Sering')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/validasi-qr/00000000-0000-4000-8000-000000000000/bundle')->assertNotFound();
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

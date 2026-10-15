<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentService;
use App\Services\WordDocumentNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WordFixtures;
use Tests\TestCase;

class DocumentUploadPreviewTest extends TestCase
{
    use RefreshDatabase;
    use WordFixtures;

    private int $conversionStatus = 200;

    private User $proposer;

    private User $verifier;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['documents.renderer' => 'gotenberg']);
        Http::fake(fn () => Http::response('%PDF-1.4 reviewed document', $this->conversionStatus));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'dokumen.buat', 'guard_name' => 'web']);
        Role::create(['name' => 'pengusul', 'guard_name' => 'web'])->givePermissionTo('dokumen.buat');
        Role::create(['name' => 'asesor_internal', 'guard_name' => 'web']);
        Role::create(['name' => 'penandatangan', 'guard_name' => 'web']);
        $unit = Unit::create(['nama' => 'Pelayanan', 'kode' => 'PEL', 'urutan' => 1]);
        $this->proposer = User::factory()->create(['unit_id' => $unit->id, 'is_active' => true]);
        $this->proposer->assignRole('pengusul');
        $this->verifier = User::factory()->create(['unit_id' => $unit->id, 'is_active' => true]);
        $this->verifier->assignRole('asesor_internal');
        User::factory()->create(['unit_id' => $unit->id, 'is_active' => true])->assignRole('penandatangan');
        $this->type = DocumentType::create(['nama' => 'Surat', 'kode' => 'SRT', 'singkatan' => 'SRT', 'format_nomor' => '{urut}/SRT/{tahun}', 'is_active' => true, 'urutan' => 1]);
        $workflow = WorkflowTemplate::create(['nama' => 'Surat', 'document_type_id' => $this->type->id, 'is_active' => true, 'is_default' => true]);
        WorkflowStep::create(['workflow_template_id' => $workflow->id, 'urutan' => 1, 'nama_tahap' => 'Verifikasi', 'tipe' => 'verifikasi', 'mode_verifikasi' => 'serial', 'min_approval' => 1, 'sla_hari_kerja' => 2]);
        WorkflowStep::create(['workflow_template_id' => $workflow->id, 'urutan' => 2, 'nama_tahap' => 'Pengesahan', 'tipe' => 'penandatangan', 'role_nama' => 'penandatangan', 'mode_verifikasi' => 'serial', 'min_approval' => 1, 'sla_hari_kerja' => 2]);
        $this->actingAs($this->proposer);
    }

    private function upload(?UploadedFile $file = null): Document
    {
        $response = $this->post(route('dokumen.store'), [
            'judul' => 'Surat pengujian', 'document_type_id' => $this->type->id,
            'perihal' => 'Data tetap tersimpan', 'submit_mode' => 'ajukan',
            'verifikator_ids' => [$this->verifier->id],
            'file_dokumen' => $file ?? $this->wordUpload(),
        ]);
        $response->assertSessionHasNoErrors();
        $document = Document::latest('id')->firstOrFail();
        $response->assertRedirect(route('dokumen.show', $document));

        return $document;
    }

    private function review(Document $document): string
    {
        $response = $this->getJson(route('dokumen.preview-pdf', [$document, $document->fresh()->currentVersion->id]));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotEmpty($response->headers->get('X-Document-Review'));

        return $response->headers->get('X-Document-Review');
    }

    public function test_upload_stays_draft_until_reviewed_version_is_submitted(): void
    {
        $document = $this->upload();
        $this->assertSame(Document::STATUS_DRAFT, $document->status);
        $this->assertSame(0, $document->verifications()->count());
        $this->get(route('dokumen.show', $document))->assertOk()->assertSee('data-document-submit disabled', false);
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $token = $this->review($document);
        $submit = $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token]);
        $submit->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Document::STATUS_DIAJUKAN, $document->fresh()->status);
        $this->assertSame('%PDF-1.4 reviewed document', Storage::disk('local')->get($document->fresh()->currentVersion->file_pdf_path));
        $this->getJson(route('dokumen.preview-pdf', [$document, $document->fresh()->currentVersion->id]))->assertOk();
        Http::assertSentCount(1);
    }

    public function test_uploading_a_replacement_invalidates_old_review_without_losing_metadata(): void
    {
        $document = $this->upload();
        $original = $document->currentVersion;
        $token = $this->review($document);
        $this->post(route('dokumen.upload-versi', $document), ['file_dokumen' => $this->wordUpload(name: 'revisi.DOCX')])->assertSessionHasNoErrors();
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertSame('Data tetap tersimpan', $document->fresh()->perihal);
        $this->assertSame(1, $document->fresh()->currentVersion->versi);
        $this->assertSame($original->id, $document->fresh()->currentVersion->id);
        $this->assertSame(1, $document->versions()->count());
        $this->assertNotSame($original->file_path, $document->fresh()->currentVersion->file_path);
        Storage::disk('local')->assertMissing($original->file_path);
        $this->assertSame(Document::STATUS_DRAFT, $document->fresh()->status);
        $this->review($document);
    }

    public function test_even_reuploading_identical_bytes_requires_review_of_the_replacement(): void
    {
        $file = $this->wordUpload();
        $document = $this->upload($file);
        $token = $this->review($document);
        $this->post(route('dokumen.upload-versi', $document), ['file_dokumen' => $file])->assertSessionHasNoErrors();
        $this->assertSame(1, $document->versions()->count());
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->review($document);
    }

    public function test_revision_creates_one_version_per_return_and_preserves_submitted_sources(): void
    {
        $document = $this->upload();
        $original = $document->currentVersion;
        $originalBytes = Storage::disk('local')->get($original->file_path);
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $this->review($document)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $snapshot = $original->fresh()->file_pdf_path;
        $this->actingAs($this->verifier);
        app(DocumentService::class)->mintaRevisi($document->verifications()->firstOrFail(), 'Perbaiki isi dokumen');
        $this->actingAs($this->proposer);

        $this->post(route('dokumen.upload-versi', $document), ['file_dokumen' => $this->wordUpload('<w:p><w:r><w:t>Perbaikan pertama</w:t></w:r></w:p>')])
            ->assertSessionHasNoErrors();
        $revision = $document->fresh()->currentVersion;
        $this->assertSame(2, $revision->versi);
        $token = $this->review($document);
        $this->post(route('dokumen.upload-versi', $document), ['file_dokumen' => $this->wordUpload('<w:p><w:r><w:t>Perbaikan kedua</w:t></w:r></w:p>')])
            ->assertSessionHasNoErrors();
        $this->assertSame($revision->id, $document->fresh()->currentVersion->id);
        $this->assertSame(2, $document->versions()->count());
        $this->assertSame($originalBytes, Storage::disk('local')->get($original->file_path));
        Storage::disk('local')->assertExists($snapshot);
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $this->review($document)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $document->fresh()->currentVersion->versi);
        $this->assertSame(Document::STATUS_DIAJUKAN, $document->fresh()->status);

        $this->actingAs($this->verifier);
        app(DocumentService::class)->mintaRevisi($document->verifications()->where('status', 'menunggu')->firstOrFail(), 'Perbaikan lanjutan');
        $this->actingAs($this->proposer);
        $this->post(route('dokumen.upload-versi', $document), ['file_dokumen' => $this->wordUpload(name: 'revisi-ketiga.docx')])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $document->fresh()->currentVersion->versi);
        $this->assertSame(3, $document->versions()->count());
    }

    public function test_reupload_is_forbidden_while_verification_is_in_progress(): void
    {
        $document = $this->upload();
        $original = $document->currentVersion;
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $this->review($document)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('dokumen.upload-versi', $document), ['file_dokumen' => $this->wordUpload()])->assertForbidden();
        $this->assertSame($original->file_path, $document->fresh()->currentVersion->file_path);
        $this->assertSame(1, $document->versions()->count());
    }

    public function test_unknown_variable_blocks_submission_receipt_but_still_allows_preview(): void
    {
        $document = $this->upload($this->wordUpload('<w:p><w:r><w:t>${tanggl_ttd}</w:t></w:r></w:p>'));
        $response = $this->getJson(route('dokumen.preview-pdf', [$document, $document->currentVersion->id]));
        $response->assertOk()->assertHeaderMissing('X-Document-Review');
        $this->assertStringContainsString('tanggl_ttd', rawurldecode($response->headers->get('X-Preview-Warnings')));
    }

    public function test_conversion_failure_preserves_source_and_provides_no_review_receipt(): void
    {
        $document = $this->upload();
        $this->conversionStatus = 503;
        $this->getJson(route('dokumen.preview-pdf', [$document, $document->currentVersion->id]))->assertStatus(503)->assertHeaderMissing('X-Document-Review');
        Storage::disk('local')->assertExists($document->currentVersion->file_path);
        $this->assertSame(Document::STATUS_DRAFT, $document->fresh()->status);
    }

    public function test_invalid_replacement_does_not_change_current_version(): void
    {
        $document = $this->upload();
        $id = $document->currentVersion->id;
        $this->postJson(route('dokumen.upload-versi', $document), ['file_dokumen' => UploadedFile::fake()->createWithContent('surat.pdf', '%PDF-1.4')])
            ->assertUnprocessable()->assertJsonValidationErrors('file_dokumen');
        $this->assertSame($id, $document->fresh()->currentVersion->id);
    }

    public function test_doc_original_is_retained_and_conversion_precedes_database_transaction(): void
    {
        $converted = $this->wordUpload();
        $normalizer = \Mockery::mock(WordDocumentNormalizer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $normalizer->shouldReceive('convertDocToDocx')->once()->andReturnUsing(function ($source, $output, $profile) use ($converted) {
            $this->assertSame(0, DB::transactionLevel() - 1, 'Only the test isolation transaction should be open.');
            copy($converted->getRealPath(), $output.'/source.docx');

            return true;
        });
        app()->instance(WordDocumentNormalizer::class, $normalizer);
        $bytes = hex2bin('d0cf11e0a1b11ae1').'original DOC bytes';
        $document = $this->upload(UploadedFile::fake()->createWithContent('original.DOC', $bytes));
        $this->assertSame($bytes, Storage::disk('local')->get($document->currentVersion->file_path.'.original.doc'));
        $this->assertSame('original.docx', $document->currentVersion->file_name);
    }

    public function test_other_user_cannot_view_private_preview_or_submit_owners_review(): void
    {
        $document = $this->upload();
        $token = $this->review($document);
        $otherUnit = Unit::create(['nama' => 'Unit lain', 'kode' => 'OTH', 'urutan' => 2]);
        $other = User::factory()->create(['unit_id' => $otherUnit->id, 'is_active' => true]);
        $other->assignRole('pengusul');
        $this->actingAs($other)->getJson(route('dokumen.preview-pdf', [$document, $document->currentVersion->id]))->assertForbidden();
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token])->assertForbidden();
    }

    public function test_preview_pruning_keeps_reviewed_and_recent_files(): void
    {
        Storage::disk('local')->put('pdf_cache/old.pdf', '%PDF-1.4');
        Storage::disk('local')->put('pdf_cache/recent.pdf', '%PDF-1.4');
        Storage::disk('local')->put('documents/1/reviewed/1.pdf', '%PDF-1.4');
        touch(Storage::disk('local')->path('pdf_cache/old.pdf'), now()->subDays(8)->timestamp);
        touch(Storage::disk('local')->path('documents/1/reviewed/1.pdf'), now()->subDays(8)->timestamp);
        $this->artisan('documents:prune-previews')->assertSuccessful();
        Storage::disk('local')->assertMissing('pdf_cache/old.pdf');
        Storage::disk('local')->assertExists('pdf_cache/recent.pdf');
        Storage::disk('local')->assertExists('documents/1/reviewed/1.pdf');
    }

    public function test_changed_source_cannot_use_an_old_review_receipt(): void
    {
        $document = $this->upload();
        $token = $this->review($document);
        Storage::disk('local')->put($document->currentVersion->file_path, 'changed source');
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertSame(Document::STATUS_DRAFT, $document->fresh()->status);
    }

    public function test_doc_conversion_service_error_keeps_form_values_and_explains_the_problem(): void
    {
        config(['documents.libreoffice_path' => '/missing/soffice']);
        $this->from(route('dokumen.create'))->post(route('dokumen.store'), [
            'judul' => 'Judul tetap ada', 'document_type_id' => $this->type->id, 'submit_mode' => 'internal',
            'file_dokumen' => UploadedFile::fake()->createWithContent('surat.doc', hex2bin('d0cf11e0a1b11ae1').'content'),
        ])->assertRedirect(route('dokumen.create'))->assertSessionHasErrors('file_dokumen')->assertSessionHasInput('judul', 'Judul tetap ada');
        $this->assertSame(0, Document::count());
    }

    public function test_review_cannot_be_reused_for_another_document_or_after_expiry(): void
    {
        $first = $this->upload();
        $token = $this->review($first);
        $second = $this->upload();
        $payload = ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token];
        $this->postJson(route('dokumen.ajukan', $second), $payload)->assertUnprocessable();
        $this->travel(2)->days();
        $this->postJson(route('dokumen.ajukan', $first), $payload)->assertUnprocessable();
    }
}

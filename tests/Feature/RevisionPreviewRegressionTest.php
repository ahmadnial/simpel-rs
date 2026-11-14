<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WordFixtures;
use Tests\TestCase;

class RevisionPreviewRegressionTest extends TestCase
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

    public function test_old_receipt_is_rejected_after_revision_and_fresh_review_preserves_snapshot(): void
    {
        $document = $this->upload();
        $token = $this->review($document);
        $payload = ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token];
        $this->postJson(route('dokumen.ajukan', $document), $payload)->assertRedirect();
        $snapshot = $document->fresh()->currentVersion->file_pdf_path;
        $this->actingAs($this->verifier);
        app(DocumentService::class)->mintaRevisi($document->verifications()->firstOrFail(), 'Perbaiki');
        $this->actingAs($this->proposer);
        $this->postJson(route('dokumen.ajukan', $document), $payload)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertSame(Document::STATUS_REVISI, $document->fresh()->status);
        $payload['review_token'] = $this->review($document);
        $this->postJson(route('dokumen.ajukan', $document), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Document::STATUS_DIAJUKAN, $document->fresh()->status);
        $this->assertSame(1, $document->versions()->count());
        $this->assertSame($snapshot, $document->fresh()->currentVersion->file_pdf_path);
        $this->actingAs($this->verifier);
        app(DocumentService::class)->mintaRevisi($document->verifications()->where('status', 'menunggu')->firstOrFail(), 'Perbaiki lagi');
        $this->actingAs($this->proposer);
        // Both receipts were issued in status revisi; the ticket cycle must also differ.
        $this->postJson(route('dokumen.ajukan', $document), $payload)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $payload['review_token'] = $this->review($document);
        $this->postJson(route('dokumen.ajukan', $document), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($snapshot, $document->fresh()->currentVersion->file_pdf_path);
        $this->assertCount(1, Storage::disk('local')->files("documents/{$document->id}/reviewed"));
    }

    public function test_historical_preview_uses_saved_pdf_during_revision_without_converter(): void
    {
        $document = $this->upload();
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $this->review($document)])->assertRedirect();
        $original = $document->fresh()->currentVersion;
        Storage::disk('local')->assertExists($original->file_pdf_path);
        $this->actingAs($this->verifier);
        app(DocumentService::class)->mintaRevisi($document->verifications()->firstOrFail(), 'Perbaiki');
        $this->actingAs($this->proposer);
        $this->post(route('dokumen.upload-versi', $document), ['file_dokumen' => $this->wordUpload(name: 'revisi.docx')])->assertSessionHasNoErrors();
        Storage::disk('local')->deleteDirectory('pdf_cache');
        $this->conversionStatus = 503;
        $this->getJson(route('dokumen.preview-pdf', [$document, $original->id]))->assertOk()->assertHeaderMissing('X-Document-Review');
        $this->get(route('dokumen.download-pdf', [$document, $original->id]))->assertOk();
        Http::assertSentCount(1);
        Storage::disk('local')->assertExists($original->file_pdf_path);
    }

    public function test_revision_form_uses_assigned_template_even_when_deactivated(): void
    {
        $document = $this->upload();
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $this->review($document)])->assertRedirect();
        $this->actingAs($this->verifier);
        app(DocumentService::class)->mintaRevisi($document->verifications()->firstOrFail(), 'Perbaiki');
        $document->fresh()->workflowTemplate->update(['is_active' => false]);
        $this->actingAs($this->proposer);
        $this->get(route('dokumen.show', $document))->assertOk()
            ->assertSee('salinan pengajuan sebelumnya')
            ->assertDontSee('data-preview-markers', false)
            ->assertDontSee('Jenis naskah ini belum bisa diajukan')
            ->assertSee('data-document-submit', false);
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $this->review($document)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Document::STATUS_DIAJUKAN, $document->fresh()->status);
    }

    public function test_revision_at_upper_automatic_step_does_not_require_manual_first_step_selection(): void
    {
        $document = $this->upload();
        $template = WorkflowTemplate::firstOrFail();
        $template->steps()->where('tipe', 'penandatangan')->update(['urutan' => 3]);
        $upper = WorkflowStep::create(['workflow_template_id' => $template->id, 'urutan' => 2, 'nama_tahap' => 'Verifikasi lanjutan', 'tipe' => 'verifikasi', 'role_nama' => 'asesor_internal', 'mode_verifikasi' => 'serial', 'min_approval' => 1, 'sla_hari_kerja' => 2]);
        $upperUser = User::factory()->create(['unit_id' => $document->unit_id, 'is_active' => true]);
        $upperUser->assignRole('asesor_internal');
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $this->review($document)])->assertRedirect();
        $this->actingAs($this->verifier);
        app(DocumentService::class)->setujui($document->verifications()->firstOrFail());
        $this->actingAs($upperUser);
        app(DocumentService::class)->mintaRevisi($document->verifications()->where('status', 'menunggu')->firstOrFail(), 'Perbaiki');
        $this->actingAs($this->proposer);
        $this->get(route('dokumen.show', $document))->assertOk()->assertSee('Verifikasi lanjutan')->assertDontSee('id="verifikator_ids_modal"', false);
        $this->postJson(route('dokumen.ajukan', $document), ['ajukan_langsung' => 1, 'review_token' => $this->review($document)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($upper->urutan, $document->fresh()->current_step);
        $this->assertSame($upper->id, $document->verifications()->where('status', 'menunggu')->firstOrFail()->workflow_step_id);
    }

    public function test_receipt_is_invalidated_when_document_metadata_changes(): void
    {
        $document = $this->upload();
        $token = $this->review($document);
        $document->update(['judul' => 'Judul yang berubah']);
        $this->postJson(route('dokumen.ajukan', $document), ['verifikator_ids' => [$this->verifier->id], 'review_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertSame(Document::STATUS_DRAFT, $document->fresh()->status);
    }
}

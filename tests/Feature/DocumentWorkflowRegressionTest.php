<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVerification;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentPdfService;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeDocumentPdfService;
use Tests\Support\RequestsSigningOtp;
use Tests\Support\WordFixtures;
use Tests\TestCase;

/** Regressions from the production workflow audit. Uses synthetic, isolated fixtures. */
class DocumentWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase, RequestsSigningOtp, WordFixtures;

    private User $owner;

    private User $verifier;

    private User $signer;

    private User $outsider;

    private WorkflowTemplate $workflow;

    private WorkflowStep $step;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Storage::fake('local');
        Notification::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['dokumen.buat', 'dokumen.verifikasi', 'dokumen.publikasi', 'dokumen.tanda_tangan'] as $permission) {
            Permission::create(['name' => $permission, 'guard_name' => 'web']);
        }
        foreach (['pengusul', 'asesor_internal', 'verifikator', 'penandatangan', 'publikator'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        Role::findByName('pengusul')->givePermissionTo('dokumen.buat');
        Role::findByName('asesor_internal')->givePermissionTo('dokumen.verifikasi');
        Role::findByName('publikator')->givePermissionTo('dokumen.publikasi');
        $unit = Unit::create(['nama' => 'Audit synthetic unit', 'kode' => 'AUD', 'urutan' => 1]);
        $this->owner = User::factory()->create(['unit_id' => $unit->id, 'is_active' => true]);
        $this->owner->assignRole('pengusul');
        $this->verifier = User::factory()->create(['unit_id' => $unit->id, 'is_active' => true]);
        $this->verifier->assignRole('asesor_internal');
        $this->signer = User::factory()->create(['unit_id' => $unit->id, 'is_active' => true]);
        $this->signer->assignRole('penandatangan');
        $otherUnit = Unit::create(['nama' => 'Unrelated synthetic unit', 'kode' => 'OTH', 'urutan' => 2]);
        $this->outsider = User::factory()->create(['unit_id' => $otherUnit->id, 'is_active' => true]);
        $type = DocumentType::create(['nama' => 'Audit type', 'kode' => 'AUD', 'singkatan' => 'AUD', 'format_nomor' => '{urut}/AUD/{tahun}', 'is_active' => true, 'urutan' => 1]);
        $this->workflow = WorkflowTemplate::create(['nama' => 'Audit workflow', 'document_type_id' => $type->id, 'is_default' => true, 'is_active' => true]);
        $this->step = WorkflowStep::create(['workflow_template_id' => $this->workflow->id, 'urutan' => 1, 'nama_tahap' => 'Review', 'tipe' => 'verifikasi', 'mode_verifikasi' => 'parallel', 'min_approval' => 1]);
        $this->step->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => 'asesor_internal']);
        WorkflowStep::create(['workflow_template_id' => $this->workflow->id, 'urutan' => 2, 'nama_tahap' => 'Sign', 'tipe' => 'penandatangan', 'mode_verifikasi' => 'serial', 'role_nama' => 'penandatangan']);
        $this->actingAs($this->owner);
        app()->instance(DocumentPdfService::class, new FakeDocumentPdfService);
        $this->document = app(DocumentService::class)->uploadDraft(['judul' => 'Synthetic audit document', 'document_type_id' => $type->id], $this->wordUpload('<w:p><w:r><w:t>${tanggal_surat}</w:t></w:r></w:p>'));
    }

    private function submit(): void
    {
        app(DocumentService::class)->ajukanDokumen($this->document, []);
    }

    public static function publicationActions(): array
    {
        return [['publikasi', Document::STATUS_DITANDATANGANI], ['unpublish', Document::STATUS_DIPUBLIKASIKAN], ['republish', Document::STATUS_DITARIK]];
    }

    #[DataProvider('publicationActions')]
    public function test_unrelated_user_without_publication_permission_is_forbidden(string $action, string $status): void
    {
        $this->document->update(['status' => $status]);
        $this->assertFalse($this->document->isAccessibleBy($this->outsider));
        $this->assertFalse($this->outsider->hasPermissionTo('dokumen.publikasi'));
        $response = $this->actingAs($this->outsider)->post(route('publikasi.'.$action, $this->document), [
            'visibility_scope' => 'internal', 'alasan_penarikan' => 'Unauthorized synthetic attempt',
        ]);
        $this->assertSame(403, $response->status(), 'Actual document state after unauthorized request: '.$this->document->fresh()->status);
        $this->assertSame($status, $this->document->fresh()->status);
    }

    public static function autoAssignmentModes(): array
    {
        return [['role'], ['user'], ['serial_role']];
    }

    #[DataProvider('autoAssignmentModes')]
    public function test_proposer_cannot_approve_own_document_through_automatic_pool(string $mode): void
    {
        $this->owner->assignRole('asesor_internal');
        if ($mode === 'user') {
            $this->step->verifierPool()->delete();
            $this->step->verifierPool()->create(['tipe_pool' => 'user', 'user_id' => $this->owner->id]);
            $this->step->verifierPool()->create(['tipe_pool' => 'user', 'user_id' => $this->verifier->id]);
        } elseif ($mode === 'serial_role') {
            $this->step->update(['mode_verifikasi' => 'serial', 'role_nama' => 'asesor_internal']);
        }
        $this->submit();
        $ticket = $this->document->verifications()->where('verifikator_id', $this->owner->id)->first();
        if ($ticket) {
            $response = $this->post(route('verifikasi.setujui', $ticket), ['catatan' => 'Own document']);
            $this->assertSame(403, $response->status(), 'Own ticket became '.$ticket->fresh()->status.'; document became '.$this->document->fresh()->status);
        }
        $this->assertSame(Document::STATUS_DIAJUKAN, $this->document->fresh()->status);
    }

    public function test_deactivated_verifier_session_cannot_approve(): void
    {
        $this->submit();
        $ticket = $this->document->verifications()->sole();
        $this->actingAs($this->verifier);
        $this->verifier->update(['is_active' => false]);
        // Rehydration models a subsequent request with the existing session.
        $response = $this->actingAs($this->verifier->fresh())->post(route('verifikasi.setujui', $ticket));
        $this->assertSame(403, $response->status(), 'Inactive actor ticket became '.$ticket->fresh()->status.'; document became '.$this->document->fresh()->status);
        $this->assertSame('menunggu', $ticket->fresh()->status);
    }

    public function test_direct_pool_does_not_assign_inactive_user(): void
    {
        $this->step->verifierPool()->delete();
        $this->verifier->update(['is_active' => false]);
        $this->step->verifierPool()->create(['tipe_pool' => 'user', 'user_id' => $this->verifier->id]);
        try {
            $this->submit();
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(Document::STATUS_DRAFT, $this->document->fresh()->status, 'An inactive-only pool must not strand a submitted document.');
    }

    public function test_failure_to_create_workflow_tickets_does_not_persist_review_snapshot(): void
    {
        $version = $this->document->fresh()->currentVersion;
        $token = $this->getJson(route('dokumen.preview-pdf', [$this->document, $version->id]))->assertOk()->headers->get('X-Document-Review');
        $this->step->verifierPool()->delete();
        $this->postJson(route('dokumen.ajukan', $this->document), ['review_token' => $token, 'ajukan_langsung' => 1])->assertUnprocessable();
        $this->assertSame(Document::STATUS_DRAFT, $this->document->fresh()->status);
        $this->assertNull($version->fresh()->file_pdf_path);
        $this->assertSame([], Storage::disk('local')->files("documents/{$this->document->id}/reviewed"));
    }

    public function test_second_pre_authorized_submission_cannot_overwrite_committed_snapshot(): void
    {
        $version = $this->document->fresh()->currentVersion;
        $token = $this->getJson(route('dokumen.preview-pdf', [$this->document, $version->id]))->assertOk()->headers->get('X-Document-Review');
        $snapshot = "documents/{$this->document->id}/reviewed/{$version->id}.pdf";
        $firstToken = $this->getJson(route('dokumen.preview-pdf', [$this->document, $version->id, 'markers' => 0]))->assertOk()->headers->get('X-Document-Review');
        $firstReview = json_decode(Crypt::decryptString($firstToken), true, flags: JSON_THROW_ON_ERROR);
        $secondReview = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        $firstBytes = file_get_contents($firstReview['pdf_path']);
        $this->assertNotSame($firstReview['pdf_hash'], $secondReview['pdf_hash'], 'Both requests reviewed genuine, distinct PDFs for the same source/version.');
        $interleaved = false;
        // Deterministic interleaving: request B already passed policy when A commits.
        Gate::after(function ($user, $ability, $result, $arguments) use (&$interleaved, $snapshot, $firstBytes, $version) {
            if ($ability === 'update' && $result === true && ! $interleaved) {
                $interleaved = true;
                Storage::disk('local')->put($snapshot, $firstBytes);
                $version->update(['file_pdf_path' => $snapshot]);
                $this->submit();
            }
        });
        $this->postJson(route('dokumen.ajukan', $this->document), ['review_token' => $token, 'ajukan_langsung' => 1])->assertForbidden();
        $this->assertTrue($interleaved);
        $this->assertSame(Document::STATUS_DIAJUKAN, $this->document->fresh()->status);
        $this->assertSame($snapshot, $version->fresh()->file_pdf_path);
        $this->assertSame($firstBytes, Storage::disk('local')->get($snapshot), 'Rejected request must not change the PDF of the committed submission.');
    }

    public function test_current_active_ticket_can_approve_and_stale_ticket_cannot_replay(): void
    {
        $this->submit();
        $ticket = $this->document->verifications()->sole();
        $this->actingAs($this->verifier)->post(route('verifikasi.setujui', $ticket))->assertRedirect();
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $this->document->fresh()->status);
        $this->post(route('verifikasi.setujui', $ticket))->assertStatus(409);
        $this->assertSame(1, $this->document->verifications()->where('status', 'disetujui')->count());
    }

    public function test_revision_closes_old_ticket_and_old_receipt_cannot_submit_replacement(): void
    {
        $version = $this->document->fresh()->currentVersion;
        $token = $this->getJson(route('dokumen.preview-pdf', [$this->document, $version->id]))->assertOk()->headers->get('X-Document-Review');
        $this->submit();
        $ticket = $this->document->verifications()->sole();
        $this->actingAs($this->verifier)->post(route('verifikasi.revisi', $ticket), ['catatan' => 'Synthetic revision'])->assertRedirect();
        $this->assertSame(Document::STATUS_REVISI, $this->document->fresh()->status);
        $this->actingAs($this->owner)->post(route('dokumen.upload-versi', $this->document), ['file_dokumen' => $this->wordUpload('<w:p><w:r><w:t>Changed</w:t></w:r></w:p>')])->assertRedirect();
        $this->postJson(route('dokumen.ajukan', $this->document), ['review_token' => $token, 'ajukan_langsung' => 1])->assertUnprocessable();
        $this->actingAs($this->verifier)->post(route('verifikasi.setujui', $ticket))->assertStatus(409);
    }

    public function test_other_unit_cannot_read_private_preview_or_replace_source(): void
    {
        $this->actingAs($this->outsider)->getJson(route('dokumen.preview-pdf', $this->document))->assertForbidden();
        $this->postJson(route('dokumen.upload-versi', $this->document), ['file_dokumen' => $this->wordUpload()])->assertForbidden();
        $this->assertSame(1, $this->document->versions()->count());
    }

    public function test_local_migrations_preserve_filtered_active_ticket_index(): void
    {
        $index = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='index' AND name='document_verifications_active_ticket_unique'");
        $this->assertStringContainsString("WHERE status = 'menunggu'", $index->sql, 'Production sys.indexes has this filter; SQLite migration result must preserve it.');
    }

    private function addSecondReviewLevel(): User
    {
        $this->workflow->steps()->where('tipe', 'penandatangan')->update(['urutan' => 3]);
        WorkflowStep::create(['workflow_template_id' => $this->workflow->id, 'urutan' => 2, 'nama_tahap' => 'Upper review', 'tipe' => 'verifikasi', 'mode_verifikasi' => 'serial', 'role_nama' => 'verifikator']);
        $upper = User::factory()->create(['unit_id' => $this->owner->unit_id, 'is_active' => true]);
        $upper->assignRole('verifikator');

        return $upper;
    }

    private function activeTicket(User $user)
    {
        return $this->document->verifications()->where('verifikator_id', $user->id)->where('status', 'menunggu')->sole();
    }

    public function test_three_repeated_returns_to_lower_level_leave_only_target_round_active(): void
    {
        $upper = $this->addSecondReviewLevel();
        $this->submit();
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $lowerTicket = $this->activeTicket($this->verifier);
            $this->actingAs($this->verifier)->post(route('verifikasi.setujui', $lowerTicket))->assertRedirect();
            $this->assertSame(2, $this->document->fresh()->current_step);
            $upperTicket = $this->activeTicket($upper);
            $this->actingAs($upper)->post(route('verifikasi.teruskan-bawah', $upperTicket), ['catatan' => 'Cycle '.$cycle])->assertRedirect();
            $this->assertSame(1, $this->document->fresh()->current_step);
            $this->assertSame('dikembalikan', $upperTicket->fresh()->status);
            $this->assertSame($cycle + 1, $this->activeTicket($this->verifier)->verification_round);
            $this->assertSame(1, $this->document->verifications()->where('status', 'menunggu')->count());
            $this->post(route('verifikasi.setujui', $upperTicket))->assertStatus(409);
        }
    }

    public function test_three_signer_returns_preserve_old_decisions_and_reopen_new_rounds(): void
    {
        $upper = $this->addSecondReviewLevel();
        $this->submit();
        $this->actingAs($this->verifier)->post(route('verifikasi.setujui', $this->activeTicket($this->verifier)))->assertRedirect();
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $ticket = $this->activeTicket($upper);
            $this->actingAs($upper)->post(route('verifikasi.setujui', $ticket))->assertRedirect();
            $this->assertSame(Document::STATUS_MENUNGGU_TTD, $this->document->fresh()->status);
            $this->actingAs($this->signer)->post(route('ttd.tolak', $this->document), ['alasan_tolak' => 'Synthetic return '.$cycle])->assertRedirect();
            $this->assertSame(Document::STATUS_DITOLAK_TTD, $this->document->fresh()->status);
            $this->assertSame('disetujui', $ticket->fresh()->status);
            $this->assertSame($cycle + 1, $this->activeTicket($upper)->verification_round);
            $this->assertSame(1, $this->document->verifications()->where('status', 'menunggu')->count());
            $this->actingAs($upper)->post(route('verifikasi.setujui', $ticket))->assertStatus(409);
        }
    }

    public function test_revision_after_partial_quorum_does_not_count_old_approval(): void
    {
        $peer = User::factory()->create(['unit_id' => $this->owner->unit_id, 'is_active' => true]);
        $peer->assignRole('asesor_internal');
        $this->step->update(['min_approval' => 2]);
        $this->submit();
        $oldApproval = $this->activeTicket($this->verifier);
        $this->actingAs($this->verifier)->post(route('verifikasi.setujui', $oldApproval))->assertRedirect();
        $this->assertSame(Document::STATUS_DIAJUKAN, $this->document->fresh()->status);
        $this->actingAs($peer)->post(route('verifikasi.revisi', $this->activeTicket($peer)), ['catatan' => 'Revise despite partial approval'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('dokumen.upload-versi', $this->document), ['file_dokumen' => $this->wordUpload('<w:p><w:r><w:t>New version</w:t></w:r></w:p>')])->assertRedirect();
        $this->submit();
        $this->actingAs($this->verifier)->post(route('verifikasi.setujui', $this->activeTicket($this->verifier)))->assertRedirect();
        $this->assertSame(Document::STATUS_DIAJUKAN, $this->document->fresh()->status);
        $this->actingAs($peer)->post(route('verifikasi.setujui', $this->activeTicket($peer)))->assertRedirect();
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $this->document->fresh()->status);
        $this->assertSame('disetujui', $oldApproval->fresh()->status);
    }

    public function test_valid_document_can_complete_submission_signature_and_authorized_publication(): void
    {
        $version = $this->document->fresh()->currentVersion;
        $token = $this->getJson(route('dokumen.preview-pdf', [$this->document, $version->id]))->assertOk()->headers->get('X-Document-Review');
        $this->postJson(route('dokumen.ajukan', $this->document), ['review_token' => $token, 'ajukan_langsung' => 1])->assertRedirect();
        $this->actingAs($this->verifier)->post(route('verifikasi.setujui', $this->activeTicket($this->verifier)))->assertRedirect();
        $this->actingAs($this->signer);
        $request = $this->requestSigningOtp($this->signer, $this->document->fresh(), 'audit-signing-session');
        $signed = app(DocumentService::class)->tandaTangani($this->document->fresh(), $request['otp'], $request['session_id']);
        $this->assertSame(Document::STATUS_DITANDATANGANI, $signed->status);
        $this->assertNotEmpty($signed->nomor_surat);
        $signature = $signed->signature;
        $this->assertSame($signature->hash_dokumen, hash_file('sha256', Storage::disk('local')->path($signature->file_signed_path)));
        $publisher = User::factory()->create(['unit_id' => $this->owner->unit_id, 'is_active' => true]);
        $publisher->assignRole('publikator');
        $this->actingAs($publisher)->post(route('publikasi.publikasi', $signed), ['visibility_scope' => 'unit', 'unit_ids' => [$this->outsider->unit_id]])->assertRedirect();
        $this->assertSame(Document::STATUS_DIPUBLIKASIKAN, $signed->fresh()->status);
        $this->assertSame(1, $signed->distributions()->count());
        $this->post(route('publikasi.unpublish', $signed), ['alasan_penarikan' => 'Synthetic withdraw'])->assertRedirect();
        $this->assertSame(Document::STATUS_DITARIK, $signed->fresh()->status);
        $this->post(route('publikasi.republish', $signed), ['visibility_scope' => 'terbatas'])->assertRedirect();
        $this->assertSame(Document::STATUS_DIPUBLIKASIKAN, $signed->fresh()->status);
        $this->assertSame(0, $signed->distributions()->count());
    }

    public function test_quorum_requires_distinct_actual_decision_makers_when_one_is_delegate(): void
    {
        $peer = User::factory()->create(['unit_id' => $this->owner->unit_id, 'is_active' => true]);
        $peer->assignRole('asesor_internal');
        $this->step->update(['min_approval' => 2]);
        Delegation::create([
            'pejabat_id' => $this->verifier->id, 'delegasi_id' => $peer->id,
            'tipe' => 'plt', 'alasan' => 'Synthetic delegate',
            'berlaku_dari' => now()->subDay()->toDateString(), 'berlaku_sampai' => now()->addDay()->toDateString(),
            'is_active' => true, 'dibuat_oleh' => $this->verifier->id,
        ]);
        $this->submit();
        $ownTicket = $this->activeTicket($peer);
        $delegatedTicket = $this->activeTicket($this->verifier);
        $this->actingAs($peer)->post(route('verifikasi.setujui', $ownTicket))->assertRedirect();
        $this->assertSame(Document::STATUS_DIAJUKAN, $this->document->fresh()->status);
        $response = $this->post(route('verifikasi.setujui', $delegatedTicket));
        $actualActors = $this->document->verifications()->where('status', 'disetujui')->distinct()->pluck('decided_by_user_id')->all();
        $this->assertSame(Document::STATUS_DIAJUKAN, $this->document->fresh()->status,
            'Quorum 2 advanced with actual actor count '.count($actualActors).'; response '.$response->status());
    }

    private function delegate(User $principal, User $actor): void
    {
        Delegation::create([
            'pejabat_id' => $principal->id, 'delegasi_id' => $actor->id,
            'tipe' => 'plt', 'alasan' => 'Synthetic delegation',
            'berlaku_dari' => now()->subDay()->toDateString(), 'berlaku_sampai' => now()->addDay()->toDateString(),
            'is_active' => true, 'dibuat_oleh' => $principal->id,
        ]);
    }

    public function test_proposer_cannot_approve_own_document_as_delegate_of_assigned_reviewer(): void
    {
        $this->delegate($this->verifier, $this->owner);
        $this->submit();
        $ticket = $this->activeTicket($this->verifier);
        $response = $this->post(route('verifikasi.setujui', $ticket));
        $this->assertSame(403, $response->status(), 'Proposer acting as delegate advanced own document to '.$this->document->fresh()->status);
    }

    public function test_actual_lower_level_actor_cannot_also_approve_upper_level(): void
    {
        $upper = $this->addSecondReviewLevel();
        $replacement = User::factory()->create(['unit_id' => $this->owner->unit_id, 'is_active' => true]);
        $replacement->assignRole('verifikator');
        $this->delegate($this->verifier, $upper);
        $this->submit();
        $lowerTicket = $this->activeTicket($this->verifier);
        $this->actingAs($upper)->post(route('verifikasi.setujui', $lowerTicket))->assertRedirect();
        $this->assertSame($upper->id, $lowerTicket->fresh()->decided_by_user_id);
        $this->assertSame(0, $this->document->verifications()->where('level', 2)->where('verifikator_id', $upper->id)->count());
        $upperTicket = $this->activeTicket($replacement);
        // Simulate a legacy/previously assigned overlapping ticket; the action guard
        // must still reject it even though new assignment now excludes this actor.
        $upperTicket->update(['verifikator_id' => $upper->id]);
        $this->assertFalse($upperTicket->fresh()->isActionable($upper->id));
        $this->assertFalse(DocumentVerification::actionable($upper->id)->whereKey($upperTicket->id)->exists());
        $response = $this->post(route('verifikasi.setujui', $upperTicket));
        $this->assertSame(409, $response->status(), 'Same actual actor approved lower and upper levels; document became '.$this->document->fresh()->status);
    }

    public function test_account_without_unit_gets_actionable_error_before_form_or_upload(): void
    {
        $this->owner->update(['unit_id' => null]);
        $this->actingAs($this->owner->fresh())->getJson(route('dokumen.create'))->assertStatus(422)->assertJsonPath('message', 'Akun belum memiliki unit kerja. Hubungi administrator untuk menetapkan unit sebelum membuat dokumen.');
        $before = Document::count();
        $this->postJson(route('dokumen.store'), [
            'judul' => 'No unit', 'document_type_id' => $this->document->document_type_id,
            'submit_mode' => 'internal', 'file_dokumen' => $this->wordUpload(),
        ])->assertStatus(422);
        $this->assertSame($before, Document::count());
    }

    public function test_missing_source_can_be_replaced_without_successful_old_preview(): void
    {
        $version = $this->document->fresh()->currentVersion;
        Storage::disk('local')->delete($version->file_path);
        $this->getJson(route('dokumen.preview-pdf', $this->document))->assertStatus(422);
        $this->post(route('dokumen.upload-versi', $this->document), ['file_dokumen' => $this->wordUpload()])->assertRedirect()->assertSessionHasNoErrors();
        $current = $this->document->fresh()->currentVersion;
        $token = $this->getJson(route('dokumen.preview-pdf', [$this->document, $current->id]))->assertOk()->headers->get('X-Document-Review');
        $this->assertNotEmpty($token);
        $this->postJson(route('dokumen.ajukan', $this->document), ['review_token' => $token, 'ajukan_langsung' => 1])->assertRedirect();
        $this->assertSame(Document::STATUS_DIAJUKAN, $this->document->fresh()->status);
    }

    public function test_legacy_self_assigned_ticket_is_hidden_and_cannot_be_approved(): void
    {
        $this->submit();
        $ticket = $this->activeTicket($this->verifier);
        $ticket->update(['verifikator_id' => $this->owner->id]);
        $this->assertFalse($ticket->fresh()->isActionable($this->owner->id));
        $this->assertFalse(DocumentVerification::actionable($this->owner->id)->whereKey($ticket->id)->exists());
        $this->post(route('verifikasi.setujui', $ticket))->assertForbidden();
        $this->assertSame('menunggu', $ticket->fresh()->status);
    }

    public function test_legacy_approval_without_actor_counts_by_ticket_owner(): void
    {
        $peer = User::factory()->create(['unit_id' => $this->owner->unit_id]);
        $peer->assignRole('asesor_internal');
        $this->step->update(['min_approval' => 2]);
        $this->submit();
        $first = $this->activeTicket($this->verifier);
        $first->update(['status' => 'disetujui', 'decided_by_user_id' => null]);
        $this->actingAs($peer)->post(route('verifikasi.setujui', $this->activeTicket($peer)))->assertRedirect();
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $this->document->fresh()->status);
    }

    public function test_snapshot_storage_failure_preserves_draft_and_allows_safe_retry(): void
    {
        $version = $this->document->fresh()->currentVersion;
        $token = $this->getJson(route('dokumen.preview-pdf', [$this->document, $version->id]))->assertOk()->headers->get('X-Document-Review');
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock($disk);
        $failed = false;
        $proxy->shouldReceive('put')->andReturnUsing(function ($path, $bytes, ...$options) use ($disk, &$failed) {
            if (! $failed && str_contains($path, '/reviewed/')) {
                $failed = true;

                return false;
            }

            return $disk->put($path, $bytes, ...$options);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $payload = ['review_token' => $token, 'ajukan_langsung' => 1];
        $this->postJson(route('dokumen.ajukan', $this->document), $payload)->assertStatus(503);
        $this->assertTrue($failed);
        $this->assertSame(Document::STATUS_DRAFT, $this->document->fresh()->status);
        $this->assertNull($version->fresh()->file_pdf_path);
        $this->assertSame([], $disk->files("documents/{$this->document->id}/reviewed"));
        $this->postJson(route('dokumen.ajukan', $this->document), $payload)->assertRedirect();
        $snapshot = $version->fresh()->file_pdf_path;
        $this->assertSame([$snapshot], $disk->files("documents/{$this->document->id}/reviewed"));
        $this->assertSame(Document::STATUS_DIAJUKAN, $this->document->fresh()->status);
    }

    public function test_publication_permission_is_required_even_when_service_is_called_directly(): void
    {
        $this->document->update(['status' => Document::STATUS_DITANDATANGANI]);
        $this->actingAs($this->outsider);
        try {
            app(DocumentService::class)->publikasi($this->document);
            $this->fail('Service must enforce publication permission.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(Document::STATUS_DITANDATANGANI, $this->document->fresh()->status);
    }

    public function test_publication_checks_current_state_instead_of_stale_bound_model(): void
    {
        $publisher = User::factory()->create(['unit_id' => $this->owner->unit_id]);
        $publisher->assignRole('publikator');
        $this->document->update(['status' => Document::STATUS_DITANDATANGANI]);
        $stale = $this->document->fresh();
        $this->document->update(['status' => Document::STATUS_DITARIK]);
        $this->actingAs($publisher);
        try {
            app(DocumentService::class)->publikasi($stale);
            $this->fail('Stale state must not republish a withdrawn document.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(Document::STATUS_DITARIK, $this->document->fresh()->status);
    }
}

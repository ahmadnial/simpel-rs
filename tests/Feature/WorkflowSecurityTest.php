<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVerification;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RequestsSigningOtp;
use Tests\TestCase;

class WorkflowSecurityTest extends TestCase
{
    use RefreshDatabase;
    use RequestsSigningOtp;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'dokumen.verifikasi', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'verifikator', 'guard_name' => 'web'])->givePermissionTo('dokumen.verifikasi');
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    public function test_general_verification_permission_cannot_take_over_another_users_ticket(): void
    {
        $fixture = $this->verificationFixture();
        $intruder = $this->user('Intruder', 'intruder@test.com', $fixture['unit']);
        $intruder->assignRole('verifikator');

        $this->actingAs($intruder)
            ->post(route('verifikasi.setujui', $fixture['upperTicket']), ['catatan' => 'ambil alih'])
            ->assertForbidden();

        $this->assertSame(DocumentVerification::STATUS_MENUNGGU, $fixture['upperTicket']->fresh()->status);
    }

    public function test_decision_actor_records_the_delegate_who_actually_approved(): void
    {
        $fixture = $this->verificationFixture();
        $delegate = $this->user('Pelaksana Plt', 'delegate-verification@test.com', $fixture['unit']);
        $signer = $this->user('Penandatangan Delegasi Test', 'signer-delegation@test.com', $fixture['unit']);
        $signer->assignRole('penandatangan');
        Delegation::create([
            'pejabat_id' => $fixture['upper']->id,
            'delegasi_id' => $delegate->id,
            'tipe' => 'plt',
            'alasan' => 'Delegasi verifikasi aktif',
            'berlaku_dari' => now()->subDay()->toDateString(),
            'berlaku_sampai' => now()->addDay()->toDateString(),
            'is_active' => true,
            'dibuat_oleh' => $fixture['upper']->id,
        ]);

        $this->actingAs($delegate);
        app(DocumentService::class)->setujui($fixture['upperTicket'], 'Disetujui oleh pelaksana delegasi.');

        $ticket = $fixture['upperTicket']->fresh()->load(['decisionMaker', 'verifikator']);
        $this->assertSame($delegate->id, $ticket->decided_by_user_id);
        $this->assertSame($delegate->name, $ticket->resolvedDecisionMaker()?->name);
        $this->assertNotSame($ticket->verifikator->name, $ticket->resolvedDecisionMaker()?->name);
    }

    public function test_history_identifies_owner_for_legacy_decision_without_explicit_actor(): void
    {
        $fixture = $this->verificationFixture();

        $this->actingAs($fixture['lower'])
            ->get(route('verifikasi.index'))
            ->assertOk()
            ->assertSee('Diputuskan Oleh')
            ->assertSee('<strong>'.$fixture['lower']->name.'</strong>', false)
            ->assertSee('Pemilik tiket · data lama');
    }

    public function test_same_verifier_at_two_levels_cannot_act_on_overlapping_assignment(): void
    {
        $fixture = $this->verificationFixture();
        $fixture['upperTicket']->update(['verifikator_id' => $fixture['lower']->id]);
        $this->actingAs($fixture['lower']);

        $this->get(route('verifikasi.show', $fixture['lowerTicket']))
            ->assertOk()
            ->assertSee('Sudah Disetujui')
            ->assertSee('Hasil Keputusan Verifikasi · Level 1')
            ->assertDontSee('id="form-setuju"', false)
            ->assertDontSee('id="form-revisi"', false)
            ->assertDontSee('id="form-kembali"', false);

        $this->get(route('verifikasi.show', $fixture['upperTicket']))
            ->assertOk()
            ->assertDontSee('id="form-setuju"', false)
            ->assertDontSee('id="form-revisi"', false);
        $this->assertFalse(DocumentVerification::actionable()->whereKey($fixture['upperTicket']->id)->exists());

        foreach (['verifikasi.setujui', 'verifikasi.revisi', 'verifikasi.teruskan-bawah'] as $route) {
            $this->post(route($route, $fixture['upperTicket']), ['catatan' => 'Tugas rangkap'])
                ->assertStatus(409);
            $this->post(route($route, $fixture['lowerTicket']), ['catatan' => 'Replay keputusan'])
                ->assertStatus(409);
        }
        $this->assertSame(DocumentVerification::STATUS_DISETUJUI, $fixture['lowerTicket']->fresh()->status);
        $this->assertSame(DocumentVerification::STATUS_MENUNGGU, $fixture['upperTicket']->fresh()->status);
    }

    public function test_actionable_scope_excludes_waiting_ticket_from_inactive_level(): void
    {
        $fixture = $this->verificationFixture();
        $fixture['upperTicket']->update(['verification_round' => 2]);
        $staleTicket = DocumentVerification::create([
            'document_id' => $fixture['document']->id,
            'document_version_id' => $fixture['version']->id,
            'workflow_step_id' => $fixture['upperStep']->id,
            'verifikator_id' => $fixture['lower']->id,
            'level' => 2,
            'verification_round' => 1,
            'status' => DocumentVerification::STATUS_MENUNGGU,
        ]);

        $actionableIds = DocumentVerification::actionable()->pluck('id');

        $this->assertTrue($actionableIds->contains($fixture['upperTicket']->id));
        $this->assertFalse($actionableIds->contains($staleTicket->id));

        $this->actingAs($fixture['lower']);
        try {
            app(DocumentService::class)->setujui($staleTicket);
            $this->fail('Tiket dari putaran lama seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_overlap_repair_is_previewed_and_preserves_other_assignee_and_old_decision(): void
    {
        $fixture = $this->verificationFixture();
        $overlap = $fixture['upperTicket']->replicate();
        $overlap->verifikator_id = $fixture['lower']->id;
        $overlap->save();
        $this->artisan('verifikasi:close-overlaps', ['document' => $fixture['document']->id])->assertSuccessful();
        $this->assertSame('menunggu', $overlap->fresh()->status);
        $this->artisan('verifikasi:close-overlaps', ['document' => $fixture['document']->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('batal', $overlap->fresh()->status);
        $this->assertNotNull($overlap->fresh()->direset_alasan);
        $this->assertSame('disetujui', $fixture['lowerTicket']->fresh()->status);
        $this->assertSame('menunggu', $fixture['upperTicket']->fresh()->status);
    }

    public function test_overlap_repair_refuses_to_leave_active_stage_without_a_verifier(): void
    {
        $fixture = $this->verificationFixture();
        $fixture['upperTicket']->update(['verifikator_id' => $fixture['lower']->id]);
        $this->artisan('verifikasi:close-overlaps', ['document' => $fixture['document']->id, '--apply' => true])->assertFailed();
        $this->assertSame('menunggu', $fixture['upperTicket']->fresh()->status);
    }

    public function test_advancement_excludes_lower_assignees_and_moves_their_decision_to_history(): void
    {
        $fixture = $this->verificationFixture();
        $this->actingAs($fixture['upper']);
        app(DocumentService::class)->turunkanKeVerifikatorBawah($fixture['upperTicket'], 'Periksa kembali');
        $lower = DocumentVerification::actionable()->where('document_id', $fixture['document']->id)->sole();
        $this->actingAs($fixture['lower']);
        app(DocumentService::class)->setujui($lower);

        $this->get(route('verifikasi.index'))->assertOk()
            ->assertViewHas('antrian', fn ($items) => $items->isEmpty())
            ->assertViewHas('riwayat', fn ($items) => $items->contains('id', $lower->id));
        $next = DocumentVerification::actionable()->where('document_id', $fixture['document']->id)->sole();
        $this->assertSame($fixture['upper']->id, $next->verifikator_id);
        $this->assertSame(2, $next->level);
    }

    public function test_insufficient_distinct_quorum_rolls_back_approval_and_advancement(): void
    {
        $fixture = $this->verificationFixture();
        $this->actingAs($fixture['upper']);
        app(DocumentService::class)->turunkanKeVerifikatorBawah($fixture['upperTicket'], 'Periksa kembali');
        $lower = DocumentVerification::actionable()->where('document_id', $fixture['document']->id)->sole();
        $fixture['upperStep']->update(['mode_verifikasi' => 'parallel', 'min_approval' => 2]);
        $fixture['upperStep']->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => 'verifikator']);
        $this->actingAs($fixture['lower']);
        try {
            app(DocumentService::class)->setujui($lower);
            $this->fail('Kuorum tidak cukup setelah pemisahan pemeriksa.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(DocumentVerification::STATUS_MENUNGGU, $lower->fresh()->status);
        $this->assertSame(1, $fixture['document']->fresh()->current_step);
        $this->assertSame(0, DocumentVerification::where('document_id', $fixture['document']->id)
            ->where('level', 2)->where('status', 'menunggu')->count());
    }

    public function test_fresh_ticket_remains_actionable_when_sql_server_hydrates_numeric_ids_as_strings(): void
    {
        $fixture = $this->verificationFixture();
        $ticket = $fixture['upperTicket']->fresh();
        $document = $fixture['document']->fresh('currentVersion');
        $step = $fixture['upperStep']->fresh();

        // Meniru nilai mentah hasil hidrasi driver sqlsrv. Regression ini menjaga
        // halaman detail dan guard transaksi agar tidak menolak tiket yang sah
        // hanya karena satu sisi ID berupa string numerik.
        $document->setRawAttributes(array_merge($document->getAttributes(), [
            'workflow_template_id' => (string) $fixture['document']->workflow_template_id,
            'current_step' => (string) $fixture['document']->current_step,
        ]), true);
        $document->currentVersion->setRawAttributes(array_merge($document->currentVersion->getAttributes(), [
            'id' => (string) $fixture['version']->id,
        ]), true);
        $step->setRawAttributes(array_merge($step->getAttributes(), [
            'workflow_template_id' => (string) $fixture['document']->workflow_template_id,
            'urutan' => (string) $ticket->level,
        ]), true);

        $ticket->setRelation('document', $document);
        $ticket->setRelation('workflowStep', $step);

        $this->assertTrue($ticket->isActionable());
    }

    public function test_revision_cancels_sibling_ticket_and_stale_ticket_cannot_be_replayed(): void
    {
        $fixture = $this->verificationFixture(false);
        $sibling = $this->user('Sibling', 'sibling@test.com', $fixture['unit']);
        $sibling->assignRole('verifikator');
        $siblingTicket = DocumentVerification::create([
            'document_id' => $fixture['document']->id,
            'document_version_id' => $fixture['version']->id,
            'workflow_step_id' => $fixture['upperStep']->id,
            'verifikator_id' => $sibling->id,
            'level' => 2,
            'status' => DocumentVerification::STATUS_MENUNGGU,
        ]);

        $this->actingAs($fixture['upper']);
        app(DocumentService::class)->mintaRevisi($fixture['upperTicket'], 'Perbaiki substansi.');
        $this->assertSame(DocumentVerification::STATUS_DIBATALKAN, $siblingTicket->fresh()->status);

        $this->actingAs($sibling);
        try {
            app(DocumentService::class)->setujui($siblingTicket);
            $this->fail('Tiket stale seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_signer_return_creates_new_round_without_reopening_approved_ticket(): void
    {
        $fixture = $this->verificationFixture();
        $signer = $this->user('Signer', 'signer-return@test.com', $fixture['unit']);
        $signer->assignRole('penandatangan');

        $this->actingAs($fixture['upper']);
        app(DocumentService::class)->setujui($fixture['upperTicket'], 'Layak diajukan kepada penandatangan.');
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);

        $this->actingAs($signer);
        app(DocumentService::class)->tolakTandaTangan($fixture['document'], 'Mohon periksa kembali dasar kebijakan.');

        $this->assertSame(DocumentVerification::STATUS_DISETUJUI, $fixture['upperTicket']->fresh()->status);
        $reopenedTicket = DocumentVerification::where('document_id', $fixture['document']->id)
            ->where('level', 2)
            ->where('verifikator_id', $fixture['upper']->id)
            ->where('status', DocumentVerification::STATUS_MENUNGGU)
            ->sole();
        $this->assertNotSame($fixture['upperTicket']->id, $reopenedTicket->id);
        $this->assertSame(2, $reopenedTicket->verification_round);
        $this->assertSame(DocumentVerification::REASON_RETURNED_BY_SIGNER, $reopenedTicket->activation_reason);
        $this->assertSame($fixture['upperTicket']->id, $reopenedTicket->reopened_from_verification_id);
        $this->assertSame(Document::STATUS_DITOLAK_TTD, $fixture['document']->fresh()->status);

        $this->actingAs($fixture['upper']);
        try {
            app(DocumentService::class)->mintaRevisi($fixture['upperTicket'], 'Replay tiket lama.');
            $this->fail('Persetujuan lama tidak boleh berubah setelah dikembalikan penandatangan.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_quorum_after_signer_return_counts_only_approvals_from_new_round(): void
    {
        $unit = Unit::create(['nama' => 'Quorum Unit', 'kode' => 'QUO', 'urutan' => 1]);
        $proposer = $this->user('Quorum Proposer', 'quorum-proposer@test.com', $unit);
        $firstVerifier = $this->user('Quorum One', 'quorum-one@test.com', $unit);
        $secondVerifier = $this->user('Quorum Two', 'quorum-two@test.com', $unit);
        $signer = $this->user('Quorum Signer', 'quorum-signer@test.com', $unit);
        $quorumRole = Role::create(['name' => 'quorum_verifier', 'guard_name' => 'web']);
        $quorumRole->givePermissionTo('dokumen.verifikasi');
        $firstVerifier->assignRole($quorumRole);
        $secondVerifier->assignRole($quorumRole);
        $signer->assignRole('penandatangan');

        $type = DocumentType::create([
            'nama' => 'Quorum Type', 'kode' => 'QUO', 'singkatan' => 'QUO',
            'format_nomor' => '{urut}/QUO/{tahun}', 'is_active' => true, 'urutan' => 1,
        ]);
        $workflow = WorkflowTemplate::create([
            'nama' => 'Quorum Workflow', 'document_type_id' => $type->id,
            'is_default' => true, 'is_active' => true,
        ]);
        $verificationStep = WorkflowStep::create([
            'workflow_template_id' => $workflow->id, 'urutan' => 1,
            'nama_tahap' => 'Quorum Review', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'parallel', 'min_approval' => 2, 'sla_hari_kerja' => 2,
        ]);
        $verificationStep->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => 'quorum_verifier']);
        WorkflowStep::create([
            'workflow_template_id' => $workflow->id, 'urutan' => 2,
            'nama_tahap' => 'Signer', 'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan', 'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2,
        ]);
        $document = $this->makeDocument($unit, $type, $workflow, $proposer, Document::STATUS_DRAFT, 0);

        $this->actingAs($proposer);
        app(DocumentService::class)->ajukanDokumen($document, []);
        foreach ([$firstVerifier, $secondVerifier] as $verifier) {
            $ticket = DocumentVerification::where('document_id', $document->id)
                ->where('verification_round', 1)
                ->where('verifikator_id', $verifier->id)
                ->sole();
            $this->actingAs($verifier);
            app(DocumentService::class)->setujui($ticket);
        }
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $document->fresh()->status);

        $this->actingAs($signer);
        app(DocumentService::class)->tolakTandaTangan($document, 'Ulangi pemeriksaan quorum.');

        $firstRoundTwo = DocumentVerification::where('document_id', $document->id)
            ->where('verification_round', 2)
            ->where('verifikator_id', $firstVerifier->id)
            ->sole();
        $this->actingAs($firstVerifier);
        app(DocumentService::class)->setujui($firstRoundTwo);
        $this->assertSame(Document::STATUS_DITOLAK_TTD, $document->fresh()->status);

        $secondRoundTwo = DocumentVerification::where('document_id', $document->id)
            ->where('verification_round', 2)
            ->where('verifikator_id', $secondVerifier->id)
            ->sole();
        $this->actingAs($secondVerifier);
        app(DocumentService::class)->setujui($secondRoundTwo);
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $document->fresh()->status);
    }

    public function test_return_to_previous_level_leaves_only_target_level_active(): void
    {
        $fixture = $this->verificationFixture();
        $this->actingAs($fixture['upper']);

        app(DocumentService::class)->turunkanKeVerifikatorBawah($fixture['upperTicket'], 'Periksa ulang dasar kebijakan.');

        $this->assertSame(DocumentVerification::STATUS_DIKEMBALIKAN, $fixture['upperTicket']->fresh()->status);
        $this->assertSame('Periksa ulang dasar kebijakan.', $fixture['upperTicket']->fresh()->catatan);
        $this->assertSame(DocumentVerification::STATUS_DISETUJUI, $fixture['lowerTicket']->fresh()->status);

        $reopenedLowerTicket = DocumentVerification::where('document_id', $fixture['document']->id)
            ->where('level', 1)
            ->where('status', DocumentVerification::STATUS_MENUNGGU)
            ->sole();
        $this->assertNotSame($fixture['lowerTicket']->id, $reopenedLowerTicket->id);
        $this->assertSame(2, $reopenedLowerTicket->verification_round);
        $this->assertSame(DocumentVerification::REASON_RETURNED_BY_VERIFIER, $reopenedLowerTicket->activation_reason);
        $this->assertSame($fixture['upperTicket']->id, $reopenedLowerTicket->reopened_from_verification_id);
        $this->assertSame(1, $fixture['document']->fresh()->current_step);
        $this->assertSame(Document::STATUS_VERIFIKASI, $fixture['document']->fresh()->status);
        $this->assertSame(1, DocumentVerification::where('document_id', $fixture['document']->id)->where('status', 'menunggu')->distinct()->count('level'));

        $this->actingAs($fixture['lower']);
        $this->get(route('verifikasi.show', $reopenedLowerTicket))
            ->assertOk()
            ->assertSee('Pemeriksaan Ulang · Putaran 2')
            ->assertSee('Periksa ulang dasar kebijakan.');

        try {
            app(DocumentService::class)->setujui($fixture['lowerTicket']);
            $this->fail('Keputusan lama tidak boleh diputar ulang.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        app(DocumentService::class)->setujui($reopenedLowerTicket, 'Diperiksa ulang.');
        $newUpperTicket = DocumentVerification::where('document_id', $fixture['document']->id)
            ->where('level', 2)
            ->where('verifikator_id', $fixture['upper']->id)
            ->where('status', DocumentVerification::STATUS_MENUNGGU)
            ->sole();

        $this->assertNotSame($fixture['upperTicket']->id, $newUpperTicket->id);
        $this->assertSame(DocumentVerification::STATUS_DIKEMBALIKAN, $fixture['upperTicket']->fresh()->status);
        $this->assertSame(2, $newUpperTicket->verification_round);

        $this->actingAs($fixture['upper']);
        try {
            app(DocumentService::class)->setujui($fixture['upperTicket']);
            $this->fail('Tiket pengembalian lama tidak boleh diputar ulang.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->get(route('verifikasi.show', $fixture['upperTicket']))
            ->assertOk()
            ->assertSee('Tiket Riwayat · Tidak Aktif')
            ->assertDontSee('id="form-setuju"', false);
    }

    public function test_super_admin_without_configured_signer_role_cannot_sign(): void
    {
        $fixture = $this->signingFixture();
        $admin = $this->user('Admin', 'admin@test.com', $fixture['unit']);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->post(route('ttd.tandatangani', $fixture['document']), ['otp' => '12345678'])
            ->assertSessionHas('error');

        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
    }

    public function test_otp_cannot_be_used_for_a_different_document(): void
    {
        $fixture = $this->signingFixture();
        $other = $this->makeDocument($fixture['unit'], $fixture['type'], $fixture['workflow'], $fixture['proposer'], Document::STATUS_MENUNGGU_TTD, 2);
        $this->actingAs($fixture['signer']);
        $otp = $this->requestSigningOtp($fixture['signer'], $fixture['document']);

        try {
            app(DocumentService::class)->tandaTangani($other, $otp['otp'], $otp['session_id']);
            $this->fail('OTP dokumen lain seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    private function verificationFixture(bool $withApprovedLower = true): array
    {
        $unit = Unit::create(['nama' => 'Unit', 'kode' => uniqid('U'), 'urutan' => 1]);
        $proposer = $this->user('Proposer', uniqid().'@test.com', $unit);
        $lower = $this->user('Lower', uniqid().'@test.com', $unit);
        $upper = $this->user('Upper', uniqid().'@test.com', $unit);
        $lower->assignRole('verifikator');
        $upper->assignRole('verifikator');
        $type = DocumentType::create(['nama' => 'Type', 'kode' => uniqid('T'), 'singkatan' => 'T', 'format_nomor' => '{urut}/T/{tahun}', 'is_active' => true, 'urutan' => 1]);
        $workflow = WorkflowTemplate::create(['nama' => 'Workflow', 'document_type_id' => $type->id, 'is_default' => true, 'is_active' => true]);
        $lowerStep = WorkflowStep::create(['workflow_template_id' => $workflow->id, 'urutan' => 1, 'nama_tahap' => 'Level 1', 'tipe' => 'verifikasi', 'role_nama' => 'verifikator', 'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2]);
        $upperStep = WorkflowStep::create(['workflow_template_id' => $workflow->id, 'urutan' => 2, 'nama_tahap' => 'Level 2', 'tipe' => 'verifikasi', 'role_nama' => 'verifikator', 'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2]);
        WorkflowStep::create(['workflow_template_id' => $workflow->id, 'urutan' => 3, 'nama_tahap' => 'Signer', 'tipe' => 'penandatangan', 'role_nama' => 'penandatangan', 'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2]);
        $document = $this->makeDocument($unit, $type, $workflow, $proposer, Document::STATUS_VERIFIKASI, 2);
        $version = $document->currentVersion;
        $lowerTicket = DocumentVerification::create(['document_id' => $document->id, 'document_version_id' => $version->id, 'workflow_step_id' => $lowerStep->id, 'verifikator_id' => $lower->id, 'level' => 1, 'status' => $withApprovedLower ? DocumentVerification::STATUS_DISETUJUI : DocumentVerification::STATUS_DIBATALKAN]);
        $upperTicket = DocumentVerification::create(['document_id' => $document->id, 'document_version_id' => $version->id, 'workflow_step_id' => $upperStep->id, 'verifikator_id' => $upper->id, 'level' => 2, 'status' => DocumentVerification::STATUS_MENUNGGU]);

        return compact('unit', 'document', 'version', 'lower', 'upper', 'lowerStep', 'upperStep', 'lowerTicket', 'upperTicket');
    }

    private function signingFixture(): array
    {
        $unit = Unit::create(['nama' => 'Unit', 'kode' => uniqid('U'), 'urutan' => 1]);
        $proposer = $this->user('Proposer', uniqid().'@test.com', $unit);
        $signer = $this->user('Signer', uniqid().'@test.com', $unit);
        $signer->assignRole('penandatangan');
        $type = DocumentType::create(['nama' => 'Type', 'kode' => uniqid('T'), 'singkatan' => 'T', 'format_nomor' => '{urut}/T/{tahun}', 'is_active' => true, 'urutan' => 1]);
        $workflow = WorkflowTemplate::create(['nama' => 'Workflow', 'document_type_id' => $type->id, 'is_default' => true, 'is_active' => true]);
        WorkflowStep::create(['workflow_template_id' => $workflow->id, 'urutan' => 1, 'nama_tahap' => 'Signer', 'tipe' => 'penandatangan', 'role_nama' => 'penandatangan', 'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2]);
        $document = $this->makeDocument($unit, $type, $workflow, $proposer, Document::STATUS_MENUNGGU_TTD, 1);

        return compact('unit', 'proposer', 'signer', 'type', 'workflow', 'document');
    }

    private function makeDocument(Unit $unit, DocumentType $type, WorkflowTemplate $workflow, User $proposer, string $status, int $step): Document
    {
        $document = Document::create(['judul' => uniqid('Doc '), 'document_type_id' => $type->id, 'unit_id' => $unit->id, 'pengusul_id' => $proposer->id, 'workflow_template_id' => $workflow->id, 'status' => $status, 'current_step' => $step]);
        DocumentVersion::create(['document_id' => $document->id, 'versi' => 1, 'file_path' => "documents/{$document->id}/test.docx", 'file_name' => 'test.docx', 'uploaded_by' => $proposer->id, 'is_current' => true]);

        return $document->fresh('currentVersion');
    }

    private function user(string $name, string $email, Unit $unit): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true]);
    }
}

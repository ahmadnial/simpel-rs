<?php

namespace Tests\Feature;

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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
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

    public function test_return_to_previous_level_leaves_only_target_level_active(): void
    {
        $fixture = $this->verificationFixture();
        $this->actingAs($fixture['upper']);

        app(DocumentService::class)->turunkanKeVerifikatorBawah($fixture['upperTicket'], 'Periksa ulang dasar kebijakan.');

        $this->assertSame(DocumentVerification::STATUS_DIBATALKAN, $fixture['upperTicket']->fresh()->status);
        $this->assertSame(DocumentVerification::STATUS_MENUNGGU, $fixture['lowerTicket']->fresh()->status);
        $this->assertSame(1, $fixture['document']->fresh()->current_step);
        $this->assertSame(Document::STATUS_VERIFIKASI, $fixture['document']->fresh()->status);
        $this->assertSame(1, DocumentVerification::where('document_id', $fixture['document']->id)->where('status', 'menunggu')->distinct()->count('level'));
    }

    public function test_super_admin_without_configured_signer_role_cannot_sign(): void
    {
        $fixture = $this->signingFixture();
        $admin = $this->user('Admin', 'admin@test.com', $fixture['unit']);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->post(route('ttd.tandatangani', $fixture['document']), ['otp' => '123456'])
            ->assertSessionHas('error');

        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $fixture['document']->fresh()->status);
    }

    public function test_otp_cannot_be_used_for_a_different_document(): void
    {
        $fixture = $this->signingFixture();
        $other = $this->makeDocument($fixture['unit'], $fixture['type'], $fixture['workflow'], $fixture['proposer'], Document::STATUS_MENUNGGU_TTD, 2);
        $this->actingAs($fixture['signer']);
        $otp = $fixture['signer']->generateOtp($fixture['document']);

        try {
            app(DocumentService::class)->tandaTangani($other, $otp);
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

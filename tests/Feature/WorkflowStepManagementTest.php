<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowStepManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private WorkflowTemplate $workflow;
    private User $poolUser;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'asesor_internal', 'guard_name' => 'web']);
        Role::create(['name' => 'penandatangan', 'guard_name' => 'web']);

        $unit = Unit::create(['nama' => 'Unit Uji', 'kode' => 'UU', 'urutan' => 1]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true]);
        $this->admin->assignRole('super_admin');
        $this->poolUser = User::create(['name' => 'Asesor', 'email' => 'asesor@test.com', 'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true]);
        $this->poolUser->assignRole('asesor_internal');

        $type = DocumentType::create(['nama' => 'Kebijakan', 'kode' => 'KBG', 'singkatan' => 'KBG', 'format_nomor' => '{urut}/KBG/{tahun}', 'is_active' => true, 'urutan' => 1]);
        $this->workflow = WorkflowTemplate::create(['nama' => 'Alur Kebijakan', 'document_type_id' => $type->id, 'is_default' => true, 'is_active' => true]);
    }

    public function test_admin_can_edit_a_parallel_step_and_replace_its_pool(): void
    {
        $step = WorkflowStep::create([
            'workflow_template_id' => $this->workflow->id, 'urutan' => 1,
            'nama_tahap' => 'Verifikasi Awal', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'parallel', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);
        $step->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => 'asesor_internal']);

        $response = $this->actingAs($this->admin)->put(route('admin.workflows.steps.update', $step), [
            'nama_tahap' => 'Telaah Asesor Internal',
            'tipe' => 'verifikasi',
            'urutan' => 1,
            'sla_hari_kerja' => 3,
            'mode_verifikasi' => 'parallel',
            'min_approval' => 1,
            'verifier_users' => [$this->poolUser->id],
        ]);

        $response->assertRedirect(route('admin.workflows.steps', $this->workflow));
        $step->refresh();
        $this->assertSame('Telaah Asesor Internal', $step->nama_tahap);
        $this->assertSame(3, $step->sla_hari_kerja);
        $this->assertSame([$this->poolUser->id], $step->verifierPool()->pluck('user_id')->all());
        $this->assertSame(0, $step->verifierPool()->where('tipe_pool', 'role')->count());
    }

    public function test_steps_page_renders_edit_payload_for_existing_step(): void
    {
        $step = WorkflowStep::create([
            'workflow_template_id' => $this->workflow->id, 'urutan' => 1,
            'nama_tahap' => 'Verifikasi Awal', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'parallel', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);
        $step->verifierPool()->create(['tipe_pool' => 'user', 'user_id' => $this->poolUser->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.workflows.steps', $this->workflow))
            ->assertOk()
            ->assertSee('data-step=', false)
            ->assertSee('Verifikasi Awal');
    }

    public function test_signer_step_cannot_be_configured_as_parallel(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.workflows.steps.store', $this->workflow), [
            'nama_tahap' => 'Pengesahan Direktur',
            'tipe' => 'penandatangan',
            'urutan' => 1,
            'sla_hari_kerja' => 2,
            'mode_verifikasi' => 'parallel',
            'min_approval' => 1,
            'verifier_users' => [$this->poolUser->id],
            'role_nama' => 'penandatangan',
        ]);

        $response->assertSessionHasErrors('mode_verifikasi');
        $this->assertDatabaseCount('workflow_steps', 0);
    }
}

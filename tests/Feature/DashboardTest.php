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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions and roles
        Permission::create(['name' => 'dokumen.verifikasi', 'guard_name' => 'web']);
        Permission::create(['name' => 'dokumen.tanda_tangan', 'guard_name' => 'web']);

        $roleVerifikator = Role::create(['name' => 'verifikator', 'guard_name' => 'web']);
        $roleVerifikator->givePermissionTo('dokumen.verifikasi');

        $rolePenandatangan = Role::create(['name' => 'penandatangan', 'guard_name' => 'web']);
        $rolePenandatangan->givePermissionTo('dokumen.tanda_tangan');
    }

    public function test_dashboard_displays_accurate_menunggu_tindakan_for_verifier_and_signer(): void
    {
        $unit = Unit::create(['nama' => 'Direktorat', 'kode' => 'DIR', 'singkatan' => 'DIR', 'urutan' => 1]);
        
        $pengusul = User::create([
            'name' => 'Pengusul User',
            'email' => 'pengusul@test.com',
            'password' => bcrypt('password'),
            'unit_id' => $unit->id,
        ]);

        $signerUser = User::create([
            'name' => 'Direktur User',
            'email' => 'direktur@test.com',
            'password' => bcrypt('password'),
            'unit_id' => $unit->id,
        ]);
        $signerUser->assignRole('penandatangan');

        $docType = DocumentType::create([
            'nama' => 'Kebijakan',
            'kode' => 'KBJ',
            'singkatan' => 'SK',
            'deskripsi' => 'SK Dir',
            'format_nomor' => '{urut}/SK/{tahun}',
            'level_verifikasi' => 1,
            'urutan' => 1,
        ]);

        $wf = WorkflowTemplate::create([
            'nama' => 'WF Kebijakan',
            'document_type_id' => $docType->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        WorkflowStep::create([
            'workflow_template_id' => $wf->id,
            'urutan' => 1,
            'nama_tahap' => 'TTD Direktur',
            'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan',
            'sla_hari_kerja' => 2,
        ]);

        // Create 2 documents waiting for signature
        Document::create([
            'judul' => 'Dokumen TTD 1',
            'document_type_id' => $docType->id,
            'unit_id' => $unit->id,
            'pengusul_id' => $pengusul->id,
            'workflow_template_id' => $wf->id,
            'status' => Document::STATUS_MENUNGGU_TTD,
        ]);

        Document::create([
            'judul' => 'Dokumen TTD 2',
            'document_type_id' => $docType->id,
            'unit_id' => $unit->id,
            'pengusul_id' => $pengusul->id,
            'workflow_template_id' => $wf->id,
            'status' => Document::STATUS_MENUNGGU_TTD,
        ]);

        $response = $this->actingAs($signerUser)->get('/');

        $response->assertStatus(200);
        $response->assertSee('Menunggu Tindakan Saya');
        $response->assertSee('Dokumen TTD 1');
        $response->assertSee('Dokumen TTD 2');
        
        $stats = $response->viewData('stats');
        $this->assertEquals(2, $stats['menunggu_tindakan']);
        $this->assertEquals(2, $stats['menunggu_ttd']);
        $this->assertEquals(0, $stats['menunggu_verifikasi']);
    }

    public function test_dashboard_combines_verification_and_ttd_for_user_with_both_roles(): void
    {
        $unit = Unit::create(['nama' => 'Direktorat', 'kode' => 'DIR', 'singkatan' => 'DIR', 'urutan' => 1]);
        
        $pengusul = User::create([
            'name' => 'Pengusul User',
            'email' => 'pengusul2@test.com',
            'password' => bcrypt('password'),
            'unit_id' => $unit->id,
        ]);

        $multiRoleUser = User::create([
            'name' => 'Pejabat Multi Role',
            'email' => 'pejabat@test.com',
            'password' => bcrypt('password'),
            'unit_id' => $unit->id,
        ]);
        $multiRoleUser->assignRole('verifikator', 'penandatangan');

        $docType = DocumentType::create([
            'nama' => 'Pedoman',
            'kode' => 'PED',
            'singkatan' => 'PED',
            'deskripsi' => 'Pedoman Pelayanan',
            'format_nomor' => '{urut}/PED/{tahun}',
            'level_verifikasi' => 1,
            'urutan' => 1,
        ]);

        $wf = WorkflowTemplate::create([
            'nama' => 'WF Pedoman',
            'document_type_id' => $docType->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        WorkflowStep::create([
            'workflow_template_id' => $wf->id,
            'urutan' => 1,
            'nama_tahap' => 'Verifikasi',
            'tipe' => 'verifikasi',
            'role_nama' => 'verifikator',
            'sla_hari_kerja' => 2,
        ]);

        WorkflowStep::create([
            'workflow_template_id' => $wf->id,
            'urutan' => 2,
            'nama_tahap' => 'TTD',
            'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan',
            'sla_hari_kerja' => 2,
        ]);

        // 1 Document in verification assigned to multiRoleUser
        $docVerif = Document::create([
            'judul' => 'Dokumen Perlu Verifikasi',
            'document_type_id' => $docType->id,
            'unit_id' => $unit->id,
            'pengusul_id' => $pengusul->id,
            'workflow_template_id' => $wf->id,
            'status' => Document::STATUS_VERIFIKASI,
        ]);

        $version = DocumentVersion::create([
            'document_id' => $docVerif->id,
            'versi' => 1,
            'file_path' => 'documents/test.docx',
            'file_name' => 'test.docx',
            'uploaded_by' => $pengusul->id,
            'is_current' => true,
        ]);

        DocumentVerification::create([
            'document_id' => $docVerif->id,
            'document_version_id' => $version->id,
            'verifikator_id' => $multiRoleUser->id,
            'level' => 1,
            'status' => DocumentVerification::STATUS_MENUNGGU,
            'batas_waktu' => now()->addDays(2),
        ]);

        // 1 Document in signature
        Document::create([
            'judul' => 'Dokumen Perlu TTD',
            'document_type_id' => $docType->id,
            'unit_id' => $unit->id,
            'pengusul_id' => $pengusul->id,
            'workflow_template_id' => $wf->id,
            'status' => Document::STATUS_MENUNGGU_TTD,
        ]);

        $response = $this->actingAs($multiRoleUser)->get('/');

        $response->assertStatus(200);
        $stats = $response->viewData('stats');
        
        $this->assertEquals(2, $stats['menunggu_tindakan']);
        $this->assertEquals(1, $stats['menunggu_verifikasi']);
        $this->assertEquals(1, $stats['menunggu_ttd']);
    }

    public function test_unauthorized_user_cannot_access_ttd_document_for_other_roles(): void
    {
        $unit = Unit::create(['nama' => 'Direktorat', 'kode' => 'DIR', 'singkatan' => 'DIR', 'urutan' => 1]);
        
        $pengusul = User::create([
            'name' => 'Pengusul User',
            'email' => 'pengusul3@test.com',
            'password' => bcrypt('password'),
            'unit_id' => $unit->id,
        ]);

        // Create a custom role 'wadir' that also has ttd permission
        $roleWadir = Role::create(['name' => 'wadir', 'guard_name' => 'web']);
        $roleWadir->givePermissionTo('dokumen.tanda_tangan');

        $wadirUser = User::create([
            'name' => 'Wadir User',
            'email' => 'wadir@test.com',
            'password' => bcrypt('password'),
            'unit_id' => $unit->id,
        ]);
        $wadirUser->assignRole('wadir');

        $docType = DocumentType::create([
            'nama' => 'SK',
            'kode' => 'SK',
            'singkatan' => 'SK',
            'deskripsi' => 'SK Direktur',
            'format_nomor' => '{urut}/SK/{tahun}',
            'level_verifikasi' => 1,
            'urutan' => 1,
        ]);

        $wf = WorkflowTemplate::create([
            'nama' => 'WF SK Direktur',
            'document_type_id' => $docType->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        WorkflowStep::create([
            'workflow_template_id' => $wf->id,
            'urutan' => 1,
            'nama_tahap' => 'TTD Direktur Saja',
            'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan', // Requires 'penandatangan' role, NOT 'wadir'
            'sla_hari_kerja' => 2,
        ]);

        $docTtd = Document::create([
            'judul' => 'Dokumen Khusus Direktur',
            'document_type_id' => $docType->id,
            'unit_id' => $unit->id,
            'pengusul_id' => $pengusul->id,
            'workflow_template_id' => $wf->id,
            'status' => Document::STATUS_MENUNGGU_TTD,
        ]);

        // Wadir should not see it in index queue
        $responseIndex = $this->actingAs($wadirUser)->get(route('ttd.index'));
        $responseIndex->assertStatus(200);
        $responseIndex->assertDontSee('Dokumen Khusus Direktur');

        // Wadir should be forbidden from accessing the show page
        $responseShow = $this->actingAs($wadirUser)->get(route('ttd.show', $docTtd));
        $responseShow->assertStatus(403);
    }
}

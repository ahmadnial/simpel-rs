<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentWorkflowChainPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['dokumen.buat', 'dokumen.ajukan', 'dokumen.lihat', 'dokumen.verifikasi', 'dokumen.tanda_tangan'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'pengusul', 'guard_name' => 'web'])
            ->givePermissionTo(['dokumen.buat', 'dokumen.ajukan', 'dokumen.lihat']);
        Role::firstOrCreate(['name' => 'asesor_internal', 'guard_name' => 'web'])
            ->givePermissionTo(['dokumen.lihat', 'dokumen.verifikasi']);
        Role::firstOrCreate(['name' => 'sekretariat', 'guard_name' => 'web'])
            ->givePermissionTo(['dokumen.lihat', 'dokumen.verifikasi']);
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web'])
            ->givePermissionTo(['dokumen.lihat', 'dokumen.tanda_tangan']);
    }

    private function buildChainFixtures(): array
    {
        $unit = Unit::create(['nama' => 'Direktorat', 'kode' => 'DIR', 'singkatan' => 'DIR', 'urutan' => 1]);

        $pengusul = User::create([
            'name' => 'Pengusul User', 'email' => 'pengusul@test.com',
            'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $pengusul->assignRole('pengusul');

        $asesor = User::create([
            'name' => 'Asesor Utama', 'email' => 'asesor@test.com', 'jabatan' => 'Asesor Internal',
            'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $asesor->assignRole('asesor_internal');

        $sekretaris = User::create([
            'name' => 'Z. Lestifani', 'email' => 'sekretaris@test.com', 'jabatan' => 'Kesekretariatan',
            'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $sekretaris->assignRole('sekretariat');

        $direktur = User::create([
            'name' => 'Dr. Direktur', 'email' => 'direktur@test.com', 'jabatan' => 'Direktur Utama',
            'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $direktur->assignRole('penandatangan');

        $docType = DocumentType::create([
            'nama' => 'SK Kebijakan', 'kode' => 'SK', 'singkatan' => 'SK',
            'deskripsi' => 'SK Direktur', 'format_nomor' => '{urut}/SK/{tahun}',
            'is_active' => true, 'urutan' => 1,
        ]);

        $wf = WorkflowTemplate::create([
            'nama' => 'WF SK Kebijakan', 'document_type_id' => $docType->id,
            'is_default' => true, 'is_active' => true,
        ]);

        // Tahap 1: manual — serial tanpa role_nama, pengusul memilih sendiri Asesor Internal.
        WorkflowStep::create([
            'workflow_template_id' => $wf->id, 'urutan' => 1,
            'nama_tahap' => 'Verifikasi Asesor', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2,
        ]);

        // Tahap 2: otomatis — serial dengan role_nama 'sekretariat'.
        WorkflowStep::create([
            'workflow_template_id' => $wf->id, 'urutan' => 2,
            'nama_tahap' => 'Verifikasi Kesekretariatan', 'tipe' => 'verifikasi',
            'role_nama' => 'sekretariat', 'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2,
        ]);

        // Tahap 3: penandatangan — role_nama 'penandatangan'.
        WorkflowStep::create([
            'workflow_template_id' => $wf->id, 'urutan' => 3,
            'nama_tahap' => 'Tanda Tangan Direktur', 'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan', 'sla_hari_kerja' => 2,
        ]);

        return compact('unit', 'pengusul', 'asesor', 'sekretaris', 'direktur', 'docType', 'wf');
    }

    public function test_create_form_exposes_full_workflow_chain_preview_per_document_type(): void
    {
        $fixtures = $this->buildChainFixtures();

        $response = $this->actingAs($fixtures['pengusul'])->get(route('dokumen.create'));

        $response->assertStatus(200);

        $chainInfo = $response->viewData('workflowChainInfo');
        $chain = $chainInfo[$fixtures['docType']->id];

        $this->assertTrue($chain['configured']);
        $this->assertCount(3, $chain['steps']);

        [$step1, $step2, $step3] = $chain['steps'];

        $this->assertSame('Verifikator 1', $step1['label']);
        $this->assertTrue($step1['manual']);
        $this->assertSame('Dipilih pengusul saat pengajuan', $step1['note']);
        $this->assertNull($step1['commonSub']);
        $this->assertSame([], $step1['people']);

        $this->assertSame('Verifikator 2', $step2['label']);
        $this->assertFalse($step2['manual']);
        $this->assertSame('Kesekretariatan', $step2['commonSub']);
        $this->assertNull($step2['note']);
        $this->assertSame([['name' => 'Z. Lestifani', 'sub' => null]], $step2['people']);

        $this->assertSame('Penandatangan', $step3['label']);
        $this->assertSame('penandatangan', $step3['tipe']);
        $this->assertSame('Direktur Utama', $step3['commonSub']);
        $this->assertSame([['name' => 'Dr. Direktur', 'sub' => null]], $step3['people']);

        // Chain harus muncul di HTML lewat data JSON yang dipakai JS form.
        $response->assertSee('workflowChainInfo', false);
    }

    public function test_workflow_chain_groups_shared_role_suffix_instead_of_repeating_it_per_person(): void
    {
        $fixtures = $this->buildChainFixtures();

        $docType2 = DocumentType::create([
            'nama' => 'SPO Layanan', 'kode' => 'SPO', 'singkatan' => 'SPO',
            'deskripsi' => 'SPO', 'format_nomor' => '{urut}/SPO/{tahun}',
            'is_active' => true, 'urutan' => 2,
        ]);

        $wf2 = WorkflowTemplate::create([
            'nama' => 'WF SPO Layanan', 'document_type_id' => $docType2->id,
            'is_default' => true, 'is_active' => true,
        ]);

        // Tahap paralel dengan kuorum: cukup 1 dari 4 Asesor Internal yang menyetujui — kasus
        // nyata yang dilaporkan user, di mana jabatan "asesor internal" dulu diulang di tiap nama
        // dan bikin barisnya sangat panjang & sulit dibaca.
        $step = WorkflowStep::create([
            'workflow_template_id' => $wf2->id, 'urutan' => 1,
            'nama_tahap' => 'Verifikasi Asesor Internal', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'parallel', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);
        $step->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => 'asesor_internal']);

        $names = ['Rika Apriliniani', 'Hervikta AW', 'Yuko Mandasari', 'Arlinda P'];
        foreach ($names as $i => $name) {
            $u = User::create([
                'name' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@test.com',
                'jabatan' => 'Asesor Internal', 'password' => bcrypt('password'),
                'unit_id' => $fixtures['unit']->id, 'is_active' => true,
            ]);
            $u->assignRole('asesor_internal');
        }
        // Ikut-sertakan asesor dari fixture dasar juga (5 total) supaya query role tidak kosong.
        $names[] = $fixtures['asesor']->name;

        $chain = app(\App\Services\DocumentService::class)->getWorkflowChainPreview($docType2, $fixtures['unit']->id);

        $this->assertTrue($chain['configured']);
        $step0 = $chain['steps'][0];

        $this->assertSame('Verifikator 1', $step0['label']);
        $this->assertSame('Asesor Internal', $step0['commonSub'], 'Jabatan yang sama untuk semua orang harus ditampilkan sekali di label, bukan diulang per nama.');
        $this->assertSame('Min. 1 dari 5 orang menyetujui', $step0['note']);
        $this->assertCount(5, $step0['people']);

        foreach ($step0['people'] as $person) {
            $this->assertNull($person['sub'], 'Sub per-orang harus kosong karena sudah diwakili commonSub.');
        }

        $this->assertEqualsCanonicalizing($names, array_column($step0['people'], 'name'));
    }

    public function test_show_page_ajukan_modal_renders_the_resolved_workflow_chain(): void
    {
        $fixtures = $this->buildChainFixtures();

        $document = Document::create([
            'judul' => 'SK Uji Coba', 'document_type_id' => $fixtures['docType']->id,
            'unit_id' => $fixtures['unit']->id, 'pengusul_id' => $fixtures['pengusul']->id,
            'status' => Document::STATUS_DRAFT,
        ]);

        DocumentVersion::create([
            'document_id' => $document->id, 'versi' => 1,
            'file_path' => 'documents/test.docx', 'file_name' => 'test.docx',
            'uploaded_by' => $fixtures['pengusul']->id, 'is_current' => true,
        ]);

        $response = $this->actingAs($fixtures['pengusul'])->get(route('dokumen.show', $document));

        $response->assertStatus(200);
        $response->assertSee('Verifikator 1');
        $response->assertSee('Verifikator 2');
        $response->assertSee('Kesekretariatan');
        $response->assertSee('Z. Lestifani');
        $response->assertSee('Penandatangan');
        $response->assertSee('Direktur Utama');
        $response->assertSee('Dr. Direktur');
    }
}

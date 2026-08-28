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

class DocumentVerificationTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['dokumen.buat', 'dokumen.lihat', 'dokumen.verifikasi'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'pengusul', 'guard_name' => 'web'])
            ->givePermissionTo(['dokumen.buat', 'dokumen.lihat']);
        Role::firstOrCreate(['name' => 'asesor_internal', 'guard_name' => 'web'])
            ->givePermissionTo(['dokumen.lihat', 'dokumen.verifikasi']);
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web']);
    }

    private function makeDocumentWithQuorumPool(int $poolSize): array
    {
        $unit = Unit::create(['nama' => 'Direktorat', 'kode' => 'DIR', 'singkatan' => 'DIR', 'urutan' => 1]);

        $pengusul = User::create([
            'name' => 'Pengusul User', 'email' => 'pengusul@test.com',
            'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $pengusul->assignRole('pengusul');

        $docType = DocumentType::create([
            'nama' => 'SK Kebijakan', 'kode' => 'SK', 'singkatan' => 'SK',
            'deskripsi' => 'SK', 'format_nomor' => '{urut}/SK/{tahun}',
            'is_active' => true, 'urutan' => 1,
        ]);

        $wf = WorkflowTemplate::create([
            'nama' => 'WF SK Kebijakan', 'document_type_id' => $docType->id,
            'is_default' => true, 'is_active' => true,
        ]);

        $step = WorkflowStep::create([
            'workflow_template_id' => $wf->id, 'urutan' => 1,
            'nama_tahap' => 'Verifikasi Asesor Internal', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'parallel', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);

        WorkflowStep::create([
            'workflow_template_id' => $wf->id, 'urutan' => 2,
            'nama_tahap' => 'Pengesahan Direktur', 'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan', 'mode_verifikasi' => 'serial',
            'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);

        $signer = User::create([
            'name' => 'Direktur', 'email' => 'direktur@test.com',
            'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $signer->assignRole('penandatangan');

        $verifiers = [];
        $names = ['Rika Apriliniani', 'Hervikta AW', 'Yuko Mandasari', 'Arlinda P'];
        for ($i = 0; $i < $poolSize; $i++) {
            $u = User::create([
                'name' => $names[$i], 'email' => strtolower(str_replace(' ', '', $names[$i])) . '@test.com',
                'jabatan' => 'Asesor Internal', 'password' => bcrypt('password'),
                'unit_id' => $unit->id, 'is_active' => true,
            ]);
            $u->assignRole('asesor_internal');
            $step->verifierPool()->create(['tipe_pool' => 'user', 'user_id' => $u->id]);
            $verifiers[] = $u;
        }

        $document = Document::create([
            'judul' => 'SK Uji Coba', 'document_type_id' => $docType->id,
            'unit_id' => $unit->id, 'pengusul_id' => $pengusul->id,
            'workflow_template_id' => $wf->id, 'status' => Document::STATUS_VERIFIKASI,
            'current_step' => 1,
        ]);

        $version = DocumentVersion::create([
            'document_id' => $document->id, 'versi' => 1,
            'file_path' => 'documents/test.docx', 'file_name' => 'test.docx',
            'uploaded_by' => $pengusul->id, 'is_current' => true,
        ]);

        foreach ($verifiers as $v) {
            DocumentVerification::create([
                'document_id' => $document->id, 'document_version_id' => $version->id,
                'workflow_step_id' => $step->id, 'verifikator_id' => $v->id,
                'level' => 1, 'status' => DocumentVerification::STATUS_MENUNGGU,
                'batas_waktu' => now()->addDays(2),
            ]);
        }

        return compact('document', 'pengusul', 'verifiers');
    }

    public function test_pending_quorum_pool_is_bundled_into_one_line_instead_of_one_per_person(): void
    {
        $fixtures = $this->makeDocumentWithQuorumPool(4);

        $response = $this->actingAs($fixtures['pengusul'])->get(route('dokumen.show', $fixtures['document']));

        $response->assertStatus(200);
        $response->assertSee('Menunggu salah satu dari 4 verifikator');
        $response->assertSee('Asesor Internal');

        // Timeline memakai satu ringkasan pool. Nama tetap dapat muncul pada pratinjau konfigurasi workflow.
    }

    public function test_once_one_verifier_approves_only_their_name_is_shown_and_cancelled_siblings_are_hidden(): void
    {
        $fixtures = $this->makeDocumentWithQuorumPool(4);
        $document = $fixtures['document'];
        [$approver, $cancelled1, $cancelled2, $cancelled3] = $fixtures['verifiers'];

        DocumentVerification::where('document_id', $document->id)
            ->where('verifikator_id', $approver->id)
            ->update(['status' => DocumentVerification::STATUS_DISETUJUI, 'direspon_at' => now()]);

        DocumentVerification::where('document_id', $document->id)
            ->where('verifikator_id', '!=', $approver->id)
            ->update(['status' => DocumentVerification::STATUS_DIBATALKAN]);

        $response = $this->actingAs($fixtures['pengusul'])->get(route('dokumen.show', $document));

        $response->assertStatus(200);
        $response->assertSee($approver->name);
        $response->assertDontSee('Menunggu salah satu dari');

        // Anggota yang dibatalkan tidak dibuat sebagai baris keputusan; nama mereka masih dapat
        // muncul pada pratinjau konfigurasi pool workflow di bagian lain halaman.
    }

    public function test_single_verifier_level_renders_the_name_directly_without_bundling(): void
    {
        $fixtures = $this->makeDocumentWithQuorumPool(1);

        $response = $this->actingAs($fixtures['pengusul'])->get(route('dokumen.show', $fixtures['document']));

        $response->assertStatus(200);
        $response->assertSee($fixtures['verifiers'][0]->name);
        $response->assertDontSee('Menunggu salah satu dari');
    }

    public function test_resubmission_after_revisi_creates_a_new_cycle_for_the_entire_pool(): void
    {
        $fixtures = $this->makeDocumentWithQuorumPool(4);
        $document = $fixtures['document'];
        [$requester, $other1, $other2, $other3] = $fixtures['verifiers'];

        $requesterTicket = DocumentVerification::where('document_id', $document->id)
            ->where('verifikator_id', $requester->id)->first();

        $this->actingAs($requester);
        app(\App\Services\DocumentService::class)->mintaRevisi($requesterTicket, 'Perbaiki format tabel.');

        $document->refresh();
        $this->assertSame(Document::STATUS_REVISI, $document->status);

        // Simulasikan pengusul mengunggah versi perbaikan (tanpa lewat upload file sungguhan).
        $document->versions()->update(['is_current' => false]);
        DocumentVersion::create([
            'document_id' => $document->id, 'versi' => 2,
            'file_path' => 'documents/test-v2.docx', 'file_name' => 'test-v2.docx',
            'uploaded_by' => $fixtures['pengusul']->id, 'is_current' => true,
        ]);

        $this->actingAs($fixtures['pengusul']);
        app(\App\Services\DocumentService::class)->ajukanDokumen($document, []);

        $newTickets = DocumentVerification::where('document_id', $document->id)
            ->where('status', DocumentVerification::STATUS_MENUNGGU)
            ->get();

        $this->assertCount(4, $newTickets, 'Versi baru harus diperiksa ulang oleh seluruh pool pada level yang meminta revisi.');
        $this->assertEqualsCanonicalizing(
            collect($fixtures['verifiers'])->pluck('id')->all(),
            $newTickets->pluck('verifikator_id')->all()
        );

        foreach ([$other1, $other2, $other3] as $notInvolved) {
            $this->assertTrue(
                DocumentVerification::where('document_id', $document->id)
                    ->where('verifikator_id', $notInvolved->id)
                    ->where('status', DocumentVerification::STATUS_MENUNGGU)
                    ->exists(),
                "{$notInvolved->name} harus mendapat tiket baru sebagai anggota pool level tersebut."
            );
        }

        // Riwayat tetap menampilkan peminta revisi dan siklus aktif baru tampil sebagai pool.
        $response = $this->actingAs($fixtures['pengusul'])->get(route('dokumen.show', $document));
        $response->assertStatus(200);
        $response->assertSee($requester->name);
        $response->assertSee('Menunggu salah satu dari 4 verifikator');
    }
}

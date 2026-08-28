<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class DocumentNumberingCollisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['dokumen.tanda_tangan', 'admin.jenis_naskah'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web'])
            ->givePermissionTo('dokumen.tanda_tangan');
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'])
            ->givePermissionTo(['dokumen.tanda_tangan', 'admin.jenis_naskah']);
    }

    public function test_admin_cannot_save_a_format_nomor_that_collides_with_another_active_document_type(): void
    {
        DocumentType::create([
            'nama' => 'Surat Keputusan', 'kode' => 'SK', 'singkatan' => 'SK',
            'deskripsi' => 'SK', 'format_nomor' => '{urut}/SK-Dir/RSNR/{bulan_romawi}/{tahun}',
            'is_active' => true, 'urutan' => 1,
        ]);

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => bcrypt('password'), 'is_active' => true,
        ]);
        $admin->assignRole('super_admin');

        $response = $this->actingAs($admin)->post(route('admin.jenis-naskah.store'), [
            'kode' => 'KEB', 'nama' => 'Kebijakan', 'singkatan' => 'SK-Dir',
            'format_nomor' => '{urut}/SK-Dir/RSNR/{bulan_romawi}/{tahun}',
            'mulai_nomor' => 1, 'is_active' => '1',
        ]);

        $response->assertSessionHasErrors('format_nomor');
        $this->assertSame(1, DocumentType::count(), 'Jenis naskah baru tidak boleh ikut tersimpan kalau format_nomor-nya bentrok.');
    }

    public function test_signing_self_heals_when_two_document_types_share_the_same_format_nomor(): void
    {
        $unit = Unit::create(['nama' => 'Direktorat', 'kode' => 'DIR', 'singkatan' => 'DIR', 'urutan' => 1]);

        $signer = User::create([
            'name' => 'Direktur', 'email' => 'direktur@test.com', 'jabatan' => 'Direktur',
            'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $signer->assignRole('penandatangan');

        $pengusul = User::create([
            'name' => 'Pengusul', 'email' => 'pengusul@test.com',
            'password' => bcrypt('password'), 'unit_id' => $unit->id, 'is_active' => true,
        ]);

        // Simulasikan data lama: 2 jenis naskah dengan format_nomor identik (skenario nyata yang
        // pernah bikin gagal TTE dengan error SQL unique constraint mentah) — dibuat langsung lewat
        // model, bukan lewat form Admin, supaya validasi baru di DocumentTypeController tidak
        // relevan di sini (tes ini soal jaring pengaman di lapisan DocumentService).
        $sharedFormat = '{urut}/SK-Dir/RSNR/{bulan_romawi}/{tahun}';
        $typeA = DocumentType::create([
            'nama' => 'Surat Keputusan', 'kode' => 'SKA', 'singkatan' => 'SK',
            'deskripsi' => 'SK', 'format_nomor' => $sharedFormat, 'is_active' => true, 'urutan' => 1,
        ]);
        $typeB = DocumentType::create([
            'nama' => 'Kebijakan', 'kode' => 'SKB', 'singkatan' => 'SK-Dir',
            'deskripsi' => 'Kebijakan', 'format_nomor' => $sharedFormat, 'is_active' => true, 'urutan' => 2,
        ]);

        $documentA = $this->makeMenungguTtdDocument($typeA, $unit, $pengusul);
        $documentB = $this->makeMenungguTtdDocument($typeB, $unit, $pengusul);

        $service = app(DocumentService::class);

        $this->actingAs($signer);
        $otpA = $signer->generateOtp($documentA);
        $signedA = $service->tandaTangani($documentA, $otpA);
        $this->assertSame('001/SK-Dir/RSNR/VIII/2026', $signedA->nomor_surat);
        $signatureA = $signedA->signature;
        $this->assertNotNull($signatureA->file_signed_path);
        $this->assertTrue(Storage::disk('local')->exists($signatureA->file_signed_path));
        $this->assertSame(
            $signatureA->hash_dokumen,
            hash_file('sha256', Storage::disk('local')->path($signatureA->file_signed_path)),
            'Hash pengesahan harus dihitung dari PDF final immutable, bukan DOCX sumber.'
        );

        $otpB = $signer->generateOtp($documentB);
        $signedB = $service->tandaTangani($documentB, $otpB);

        // Nomor urut type B sendiri juga mulai dari 1 (counter terpisah per jenis naskah), jadi
        // hasil naif-nya SAMA PERSIS dengan nomor dokumen A ("001/SK-Dir/..."). Jaring pengaman di
        // generateNomorSurat() harus mendeteksi ini lalu otomatis lompat ke nomor urut 2 milik
        // type B sendiri, BUKAN melempar error SQL unique constraint mentah ke pengguna.
        $this->assertNotSame($signedA->nomor_surat, $signedB->nomor_surat);
        $this->assertSame('002/SK-Dir/RSNR/VIII/2026', $signedB->nomor_surat);
    }

    private function makeMenungguTtdDocument(DocumentType $type, Unit $unit, User $pengusul): Document
    {
        $wf = WorkflowTemplate::create([
            'nama' => 'WF ' . $type->nama, 'document_type_id' => $type->id,
            'is_default' => true, 'is_active' => true,
        ]);

        WorkflowStep::create([
            'workflow_template_id' => $wf->id, 'urutan' => 1,
            'nama_tahap' => 'TTD Direktur', 'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan', 'sla_hari_kerja' => 2,
        ]);

        $document = Document::create([
            'judul' => 'Dokumen ' . $type->nama, 'document_type_id' => $type->id,
            'unit_id' => $unit->id, 'pengusul_id' => $pengusul->id,
            'workflow_template_id' => $wf->id, 'status' => Document::STATUS_MENUNGGU_TTD,
        ]);

        DocumentVersion::create([
            'document_id' => $document->id, 'versi' => 1,
            'file_path' => 'documents/' . $document->id . '/test.docx', 'file_name' => 'test.docx',
            'uploaded_by' => $pengusul->id, 'is_current' => true,
        ]);

        return $document->fresh();
    }
}

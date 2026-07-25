<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\User;
use App\Models\DocumentType;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles & permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ==========================================
        // 1. PERMISSIONS
        // ==========================================
        $permissions = [
            // Dokumen
            'dokumen.buat', 'dokumen.lihat', 'dokumen.edit', 'dokumen.hapus',
            'dokumen.ajukan', 'dokumen.verifikasi', 'dokumen.tanda_tangan',
            'dokumen.publikasi', 'dokumen.arsip',
            // Admin
            'admin.unit', 'admin.user', 'admin.role', 'admin.workflow',
            'admin.template', 'admin.jenis_naskah',
            // Laporan
            'laporan.lihat', 'laporan.ekspor',
            // Delegasi
            'delegasi.kelola',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // ==========================================
        // 2. ROLES
        // ==========================================
        $roles = [
            'super_admin' => $permissions, // semua permission
            'admin_unit'  => [
                'dokumen.buat', 'dokumen.lihat', 'dokumen.edit',
                'dokumen.ajukan', 'admin.user', 'admin.workflow',
                'laporan.lihat', 'delegasi.kelola',
            ],
            'pengusul' => [
                'dokumen.buat', 'dokumen.lihat', 'dokumen.edit', 'dokumen.ajukan',
            ],
            'verifikator' => [
                'dokumen.lihat', 'dokumen.verifikasi',
            ],
            'penandatangan' => [
                'dokumen.lihat', 'dokumen.tanda_tangan',
            ],
            'publikator' => [
                'dokumen.lihat', 'dokumen.publikasi', 'dokumen.arsip',
            ],
            'auditor' => [
                'dokumen.lihat', 'laporan.lihat', 'laporan.ekspor',
            ],
        ];

        foreach ($roles as $roleName => $rolePerms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePerms);
        }

        // ==========================================
        // 3. UNITS
        // ==========================================
        $direktorat = Unit::firstOrCreate(
            ['kode' => 'DIR'],
            ['nama' => 'Direktorat', 'singkatan' => 'DIR', 'urutan' => 1]
        );

        $units = [
            ['kode' => 'KABAG_UMUM',  'nama' => 'Bagian Umum & SDM',      'singkatan' => 'UMUM', 'urutan' => 2, 'parent_id' => $direktorat->id],
            ['kode' => 'KABAG_KEU',   'nama' => 'Bagian Keuangan',         'singkatan' => 'KEU',  'urutan' => 3, 'parent_id' => $direktorat->id],
            ['kode' => 'KABID_YAN',   'nama' => 'Bidang Pelayanan',        'singkatan' => 'YAN',  'urutan' => 4, 'parent_id' => $direktorat->id],
            ['kode' => 'KABID_MUTU',  'nama' => 'Bidang Mutu & Akreditasi','singkatan' => 'MUTU', 'urutan' => 5, 'parent_id' => $direktorat->id],
            ['kode' => 'IT',          'nama' => 'Instalasi IT',             'singkatan' => 'IT',   'urutan' => 6, 'parent_id' => $direktorat->id],
        ];

        foreach ($units as $unitData) {
            Unit::firstOrCreate(['kode' => $unitData['kode']], $unitData);
        }

        // ==========================================
        // 4. USERS
        // ==========================================
        $userIT = Unit::where('kode', 'IT')->first();

        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@simpel-rs.test'],
            [
                'name'     => 'Super Administrator',
                'jabatan'  => 'Administrator Sistem',
                'unit_id'  => $userIT?->id,
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );
        $superAdmin->syncRoles(['super_admin']);

        // Sample users per role
        $sampleUsers = [
            [
                'email'   => 'direktur@simpel-rs.test',
                'name'    => 'dr. Ahmad Direktur, Sp.PD',
                'jabatan' => 'Direktur Rumah Sakit',
                'role'    => 'penandatangan',
                'unit'    => 'DIR',
            ],
            [
                'email'   => 'kabag.umum@simpel-rs.test',
                'name'    => 'Budi Kabag, S.KM',
                'jabatan' => 'Kepala Bagian Umum & SDM',
                'role'    => 'verifikator',
                'unit'    => 'KABAG_UMUM',
            ],
            [
                'email'   => 'kabid.mutu@simpel-rs.test',
                'name'    => 'Siti Mutu, S.Kep, M.Kes',
                'jabatan' => 'Kepala Bidang Mutu & Akreditasi',
                'role'    => 'verifikator',
                'unit'    => 'KABID_MUTU',
            ],
            [
                'email'   => 'staf.umum@simpel-rs.test',
                'name'    => 'Andi Staf',
                'jabatan' => 'Staf Administrasi',
                'role'    => 'pengusul',
                'unit'    => 'KABAG_UMUM',
            ],
            [
                'email'   => 'admin.unit@simpel-rs.test',
                'name'    => 'Rini Admin Unit',
                'jabatan' => 'Admin Unit IT',
                'role'    => 'admin_unit',
                'unit'    => 'IT',
            ],
            [
                'email'   => 'humas@simpel-rs.test',
                'name'    => 'Dewi Publikasi',
                'jabatan' => 'Staf Humas',
                'role'    => 'publikator',
                'unit'    => 'KABAG_UMUM',
            ],
        ];

        foreach ($sampleUsers as $data) {
            $unit = Unit::where('kode', $data['unit'])->first();
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name'      => $data['name'],
                    'jabatan'   => $data['jabatan'],
                    'unit_id'   => $unit?->id,
                    'password'  => Hash::make('password'),
                    'is_active' => true,
                ]
            );
            $user->syncRoles([$data['role']]);
        }

        // ==========================================
        // 5. JENIS NASKAH (DocumentTypes)
        // ==========================================
        $docTypes = [
            [
                'kode'             => 'SK',
                'nama'             => 'Surat Keputusan',
                'singkatan'        => 'SK',
                'deskripsi'        => 'Dokumen kebijakan resmi yang ditetapkan Direktur',
                'format_nomor'     => '{urut}/SK/{unit}/RS/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 2,
                'urutan'           => 1,
            ],
            [
                'kode'             => 'SPO',
                'nama'             => 'Standar Prosedur Operasional',
                'singkatan'        => 'SPO',
                'deskripsi'        => 'Prosedur pelayanan standar',
                'format_nomor'     => '{urut}/SPO/{unit}/RS/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 2,
                'urutan'           => 2,
            ],
            [
                'kode'             => 'SE',
                'nama'             => 'Surat Edaran',
                'singkatan'        => 'SE',
                'deskripsi'        => 'Pemberitahuan resmi internal RS',
                'format_nomor'     => '{urut}/SE/{unit}/RS/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 1,
                'urutan'           => 3,
            ],
            [
                'kode'             => 'ND',
                'nama'             => 'Nota Dinas',
                'singkatan'        => 'ND',
                'deskripsi'        => 'Komunikasi resmi antar unit internal',
                'format_nomor'     => '{urut}/ND/{unit}/RS/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 1,
                'urutan'           => 4,
            ],
            [
                'kode'             => 'ST',
                'nama'             => 'Surat Tugas',
                'singkatan'        => 'ST',
                'deskripsi'        => 'Penugasan dinas luar/dalam',
                'format_nomor'     => '{urut}/ST/{unit}/RS/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 1,
                'urutan'           => 5,
            ],
        ];

        $createdTypes = [];
        foreach ($docTypes as $dt) {
            $createdTypes[$dt['kode']] = DocumentType::firstOrCreate(['kode' => $dt['kode']], $dt);
        }

        // ==========================================
        // 6. WORKFLOW TEMPLATES
        // ==========================================
        // Workflow SK: 2 level (Kabag → Direktur)
        $wfSK = WorkflowTemplate::firstOrCreate(
            ['nama' => 'Workflow SK Standard (2 Level)', 'document_type_id' => $createdTypes['SK']->id],
            ['is_default' => true, 'is_active' => true, 'deskripsi' => 'Verifikasi Kabag kemudian tanda tangan Direktur']
        );
        WorkflowStep::firstOrCreate(
            ['workflow_template_id' => $wfSK->id, 'urutan' => 1],
            ['nama_tahap' => 'Verifikasi Kepala Bagian', 'tipe' => 'verifikasi', 'role_nama' => 'verifikator', 'sla_hari_kerja' => 2]
        );
        WorkflowStep::firstOrCreate(
            ['workflow_template_id' => $wfSK->id, 'urutan' => 2],
            ['nama_tahap' => 'Tanda Tangan Direktur', 'tipe' => 'penandatangan', 'role_nama' => 'penandatangan', 'sla_hari_kerja' => 2]
        );

        // Workflow Nota Dinas: 1 level
        $wfND = WorkflowTemplate::firstOrCreate(
            ['nama' => 'Workflow Nota Dinas (1 Level)', 'document_type_id' => $createdTypes['ND']->id],
            ['is_default' => true, 'is_active' => true, 'deskripsi' => 'Langsung tanda tangan Kepala Bagian']
        );
        WorkflowStep::firstOrCreate(
            ['workflow_template_id' => $wfND->id, 'urutan' => 1],
            ['nama_tahap' => 'Tanda Tangan Kepala Bagian', 'tipe' => 'penandatangan', 'role_nama' => 'penandatangan', 'sla_hari_kerja' => 1]
        );

        $this->command->info('✅ SIMPEL-RS seeder berhasil dijalankan!');
        $this->command->table(
            ['Email', 'Role', 'Password'],
            collect($sampleUsers)->merge([['email' => 'superadmin@simpel-rs.test', 'role' => 'super_admin', 'unit' => '-']])
                ->map(fn($u) => [$u['email'], $u['role'], 'password'])->toArray()
        );
    }
}

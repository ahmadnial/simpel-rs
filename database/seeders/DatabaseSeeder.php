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
            'super_admin' => $permissions,
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
        // 3. UNITS (PARENT & SUB-UNITS RSNR)
        // ==========================================
        $direktorat = Unit::updateOrCreate(
            ['kode' => 'DIR'],
            ['nama' => 'Direktorat Utama', 'singkatan' => 'DIR', 'urutan' => 1]
        );

        $parentUnits = [
            'YANMED'  => Unit::updateOrCreate(['kode' => 'YANMED'],  ['nama' => 'Pelayanan Medis', 'singkatan' => 'YANMED', 'parent_id' => $direktorat->id, 'urutan' => 2]),
            'JANGMED' => Unit::updateOrCreate(['kode' => 'JANGMED'], ['nama' => 'Penunjang Medis', 'singkatan' => 'JANGMED', 'parent_id' => $direktorat->id, 'urutan' => 3]),
            'UMSDM'   => Unit::updateOrCreate(['kode' => 'UMSDM'],   ['nama' => 'Umum dan SDM', 'singkatan' => 'UMSDM', 'parent_id' => $direktorat->id, 'urutan' => 4]),
            'KEU'     => Unit::updateOrCreate(['kode' => 'KEU'],     ['nama' => 'Keuangan', 'singkatan' => 'KEU', 'parent_id' => $direktorat->id, 'urutan' => 5]),
            'KMT'     => Unit::updateOrCreate(['kode' => 'KMT'],     ['nama' => 'Komite RS', 'singkatan' => 'KMT', 'parent_id' => $direktorat->id, 'urutan' => 6]),
            'TIM'     => Unit::updateOrCreate(['kode' => 'TIM'],     ['nama' => 'Tim Kerja RS', 'singkatan' => 'TIM', 'parent_id' => $direktorat->id, 'urutan' => 7]),
        ];

        $subUnits = [
            // Pelayanan Medis
            ['kode' => 'RAJ',  'nama' => 'Instalasi Rawat Jalan', 'singkatan' => 'RAJ', 'parent' => 'YANMED'],
            ['kode' => 'RI',   'nama' => 'Instalasi Rawat Inap', 'singkatan' => 'RI', 'parent' => 'YANMED'],
            ['kode' => 'HD',   'nama' => 'Instalasi Hemodialisa', 'singkatan' => 'HD', 'parent' => 'YANMED'],
            ['kode' => 'IGD',  'nama' => 'Instalasi Gawat Darurat', 'singkatan' => 'IGD', 'parent' => 'YANMED'],
            ['kode' => 'BID',  'nama' => 'Instalasi Kandungan dan Kebidanan', 'singkatan' => 'BID', 'parent' => 'YANMED'],
            ['kode' => 'KEP',  'nama' => 'Unit Keperawatan', 'singkatan' => 'KEP', 'parent' => 'YANMED'],
            ['kode' => 'IRI',  'nama' => 'Instalasi Rawat Intensif', 'singkatan' => 'IRI', 'parent' => 'YANMED'],
            ['kode' => 'IBS',  'nama' => 'Instalasi Bedah Sentral', 'singkatan' => 'IBS', 'parent' => 'YANMED'],
            ['kode' => 'NCPR', 'nama' => 'NICU Perinatologi', 'singkatan' => 'NCPR', 'parent' => 'YANMED'],
            ['kode' => 'PKRS', 'nama' => 'PKRS', 'singkatan' => 'PKRS', 'parent' => 'YANMED'],

            // Penunjang Medis
            ['kode' => 'RAD',  'nama' => 'Instalasi Radiologi', 'singkatan' => 'RAD', 'parent' => 'JANGMED'],
            ['kode' => 'LAB',  'nama' => 'Instalasi Laboratorium', 'singkatan' => 'LAB', 'parent' => 'JANGMED'],
            ['kode' => 'RM',   'nama' => 'Instalasi Rekam Medis', 'singkatan' => 'RM', 'parent' => 'JANGMED'],
            ['kode' => 'SAN',  'nama' => 'Instalasi Sanitasi', 'singkatan' => 'SAN', 'parent' => 'JANGMED'],
            ['kode' => 'IF',   'nama' => 'Instalasi Farmasi', 'singkatan' => 'IF', 'parent' => 'JANGMED'],
            ['kode' => 'GZ',   'nama' => 'Instalasi Gizi', 'singkatan' => 'GZ', 'parent' => 'JANGMED'],
            ['kode' => 'LS',   'nama' => 'Instalasi Linen dan Sterilisasi', 'singkatan' => 'LS', 'parent' => 'JANGMED'],
            ['kode' => 'FIS',  'nama' => 'Instalasi Rehabmedik', 'singkatan' => 'FIS', 'parent' => 'JANGMED'],

            // Umum dan SDM
            ['kode' => 'URT',  'nama' => 'Unit Umum, RT, dan IPSRS', 'singkatan' => 'URT', 'parent' => 'UMSDM'],
            ['kode' => 'SDM',  'nama' => 'Unit SDM & Diklat', 'singkatan' => 'SDM', 'parent' => 'UMSDM'],
            ['kode' => 'IT',   'nama' => 'Instalasi SIMRS & IT', 'singkatan' => 'IT', 'parent' => 'UMSDM'],
            ['kode' => 'KJZ',  'nama' => 'Instalasi Pemulasaraan Jenazah', 'singkatan' => 'KJZ', 'parent' => 'UMSDM'],
            ['kode' => 'MKT',  'nama' => 'Unit Pemasaran & Humas', 'singkatan' => 'MKT', 'parent' => 'UMSDM'],

            // Keuangan
            ['kode' => 'KEU_SUB', 'nama' => 'Unit Perbendaharaan & Akuntansi', 'singkatan' => 'KEU', 'parent' => 'KEU'],
            ['kode' => 'PBY',     'nama' => 'Unit Pembiayaan & JKN', 'singkatan' => 'PBY', 'parent' => 'KEU'],

            // Komite
            ['kode' => 'PMKP', 'nama' => 'Komite Mutu & Keselamatan Pasien', 'singkatan' => 'PMKP', 'parent' => 'KMT'],
            ['kode' => 'PPI',  'nama' => 'Komite Pencegahan & Pengendalian Infeksi', 'singkatan' => 'PPI', 'parent' => 'KMT'],
            ['kode' => 'EHK',  'nama' => 'Komite Etik dan Hukum', 'singkatan' => 'EHK', 'parent' => 'KMT'],
            ['kode' => 'PRWT', 'nama' => 'Komite Keperawatan', 'singkatan' => 'PRWT', 'parent' => 'KMT'],
            ['kode' => 'NKSL', 'nama' => 'Komite Tenaga Kesehatan Lain', 'singkatan' => 'NKSL', 'parent' => 'KMT'],
            ['kode' => 'MED',  'nama' => 'Komite Medik', 'singkatan' => 'MED', 'parent' => 'KMT'],
        ];

        $order = 10;
        foreach ($subUnits as $su) {
            Unit::updateOrCreate(
                ['kode' => $su['kode']],
                [
                    'nama'      => $su['nama'],
                    'singkatan' => $su['singkatan'],
                    'parent_id' => $parentUnits[$su['parent']]->id,
                    'urutan'    => $order++,
                ]
            );
        }

        // ==========================================
        // 4. USERS
        // ==========================================
        $unitIT = Unit::where('kode', 'IT')->first();

        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@simpel-rs.test'],
            [
                'name'      => 'Super Administrator',
                'jabatan'   => 'Administrator Sistem',
                'unit_id'   => $unitIT?->id,
                'password'  => Hash::make('password'),
                'is_active' => true,
            ]
        );
        $superAdmin->syncRoles(['super_admin']);

        $sampleUsers = [
            [
                'email'    => 'direktur@simpel-rs.test',
                'name'     => 'dr. Ahmad Direktur, Sp.PD',
                'jabatan'  => 'Direktur Utama RSNR',
                'role'     => 'penandatangan',
                'unit'     => 'DIR',
            ],
            [
                'email'    => 'yanmed@simpel-rs.test',
                'name'     => 'dr. Budi Yanmed, Sp.B',
                'jabatan'  => 'Ketua Tim Pelayanan Medis',
                'role'     => 'verifikator',
                'unit'     => 'YANMED',
            ],
            [
                'email'    => 'staf.raj@simpel-rs.test',
                'name'     => 'Siti Staf Rawat Jalan',
                'jabatan'  => 'Staf Admin Rawat Jalan',
                'role'     => 'pengusul',
                'unit'     => 'RAJ',
            ],
        ];

        foreach ($sampleUsers as $data) {
            $unit = Unit::where('kode', $data['unit'])->first();
            $user = User::updateOrCreate(
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
        // 5. JENIS NASKAH (REAL RSNR CLASSIFICATION)
        // ==========================================
        $docTypes = [
            [
                'kode'             => 'KBJ',
                'nama'             => 'Kebijakan',
                'singkatan'        => 'SK-Dir',
                'deskripsi'        => 'Kebijakan Direktur RSNR',
                'format_nomor'     => '{urut}/SK-Dir/RSNR/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 2,
                'urutan'           => 1,
            ],
            [
                'kode'             => 'PED',
                'nama'             => 'Pedoman (Pengorganisasian, Pelayanan)',
                'singkatan'        => 'Ped',
                'deskripsi'        => 'Pedoman Pengorganisasian / Pelayanan RSNR',
                'format_nomor'     => '{urut}/SK-Dir/Ped/RSNR/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 2,
                'urutan'           => 2,
            ],
            [
                'kode'             => 'PAD',
                'nama'             => 'Panduan',
                'singkatan'        => 'Pad',
                'deskripsi'        => 'Panduan Pelayanan RSNR',
                'format_nomor'     => '{urut}/SK-Dir/Pad/RSNR/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 2,
                'urutan'           => 3,
            ],
            [
                'kode'             => 'PROG',
                'nama'             => 'Program',
                'singkatan'        => 'Prog',
                'deskripsi'        => 'Program Kerja RSNR',
                'format_nomor'     => '{urut}/SK-Dir/RSNR/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 2,
                'urutan'           => 4,
            ],
            [
                'kode'             => 'SPO',
                'nama'             => 'Standar Prosedur Operasional (SPO)',
                'singkatan'        => 'SPO',
                'deskripsi'        => 'Standar Prosedur Operasional RSNR',
                'format_nomor'     => '{urut}/{induk}-{unit}/RSNR/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 2,
                'urutan'           => 5,
            ],
            [
                'kode'             => 'LAP',
                'nama'             => 'Laporan',
                'singkatan'        => 'Lap',
                'deskripsi'        => 'Laporan Kinerja / Pelayanan RSNR',
                'format_nomor'     => '{urut}/Lap-{unit}/RSNR/{bulan_romawi}/{tahun}',
                'level_verifikasi' => 1,
                'urutan'           => 6,
            ],
        ];

        $createdTypes = [];
        foreach ($docTypes as $dt) {
            $createdTypes[$dt['kode']] = DocumentType::updateOrCreate(['kode' => $dt['kode']], $dt);
        }

        // ==========================================
        // 6. WORKFLOW TEMPLATES
        // ==========================================
        // Workflow SPO
        $wfSPO = WorkflowTemplate::updateOrCreate(
            ['nama' => 'Workflow Standard SPO (2 Level)', 'document_type_id' => $createdTypes['SPO']->id],
            ['is_default' => true, 'is_active' => true, 'deskripsi' => 'Verifikasi Kepala Unit/Instalasi/Komite kemudian TTE Direktur Utama']
        );
        WorkflowStep::updateOrCreate(
            ['workflow_template_id' => $wfSPO->id, 'urutan' => 1],
            ['nama_tahap' => 'Verifikasi Kepala Unit / Instalasi / Komite', 'tipe' => 'verifikasi', 'role_nama' => 'verifikator', 'sla_hari_kerja' => 2]
        );
        WorkflowStep::updateOrCreate(
            ['workflow_template_id' => $wfSPO->id, 'urutan' => 2],
            ['nama_tahap' => 'Tanda Tangan Direktur Utama', 'tipe' => 'penandatangan', 'role_nama' => 'penandatangan', 'sla_hari_kerja' => 2]
        );

        $this->command->info('✅ Master data SIMPEL-RS RSNR berhasil diperbarui!');
    }
}

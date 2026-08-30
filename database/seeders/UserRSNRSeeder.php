<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserRSNRSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            // 1. Unit & Instalasi Pelayanan serta Penunjang
            ['name' => 'Unit Asuhan Keperawatan dan Asuhan Kebidanan', 'email' => 'asuhan.keperawatan@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Rawat Inap', 'email' => 'irna@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Rawat Jalan', 'email' => 'irj@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Gawat Darurat', 'email' => 'igd@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Bedah Sentral', 'email' => 'ibs@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Hemodialisa', 'email' => 'ihd@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Kebidanan dan Kandungan', 'email' => 'kebidanan@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Rawat Intensif', 'email' => 'iri@rsnurrohmah.my.id'],
            ['name' => 'Instalasi NICU dan Perinatologi', 'email' => 'nicu.peri@rsnurrohmah.my.id'],
            ['name' => 'Unit PKRS', 'email' => 'pkrs@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Laboratorium', 'email' => 'laboratorium@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Radiologi', 'email' => 'radiologi@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Rehabilitasi Medik', 'email' => 'rehabilitasi.medik@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Farmasi', 'email' => 'farmasi@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Rekam Medis', 'email' => 'rm@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Sanitasi', 'email' => 'sanitasi@rsnurrohmah.my.id'],
            ['name' => 'Instalasi Gizi', 'email' => 'gizi@rsnurrohmah.my.id'],
            ['name' => 'Instalasi CSSD', 'email' => 'cssd@rsnurrohmah.my.id'],
            ['name' => 'Unit SDM', 'email' => 'sdm@rsnurrohmah.my.id'],
            ['name' => 'Unit Umum, Rumah Tangga dan PSRS', 'email' => 'umum@rsnurrohmah.my.id'],
            ['name' => 'Unit IT RS', 'email' => 'itrs@rsnurrohmah.my.id'],
            ['name' => 'Unit Kamar Jenazah', 'email' => 'kamar.jenazah@rsnurrohmah.my.id'],
            ['name' => 'Unit Pemasaran', 'email' => 'pemasaran@rsnurrohmah.my.id'],
            ['name' => 'Unit Keuangan', 'email' => 'keuangan@rsnurrohmah.my.id'],
            ['name' => 'Unit Pembiayaan', 'email' => 'pembiayaan@rsnurrohmah.my.id'],

            // 2. Komite, Tim Khusus, dan Panitia
            ['name' => 'Komite Medis', 'email' => 'komdik@rsnurrohmah.my.id'],
            ['name' => 'Komite Keperawatan', 'email' => 'komite.keperawatan@rsnurrohmah.my.id'],
            ['name' => 'Komite Tenaga Kesehatan Lain', 'email' => 'komite.naskes.lain@rsnurrohmah.my.id'],
            ['name' => 'Komite Mutu dan Keselamatan Pasien serta Manajemen Risiko', 'email' => 'komite.mutu@rsnurrohmah.my.id'],
            ['name' => 'Komite Etik dan Hukum', 'email' => 'komite.etik@rsnurrohmah.my.id'],
            ['name' => 'Komite PPI', 'email' => 'komite.ppi@rsnurrohmah.my.id'],
            ['name' => 'Tim K3', 'email' => 'tim.k3@rsnurrohmah.my.id'],
            ['name' => 'Tim PPRA', 'email' => 'tim.ppra@rsnurrohmah.my.id'],
            ['name' => 'Tim TB', 'email' => 'tim.tb@rsnurrohmah.my.id'],
            ['name' => 'Tim HIV', 'email' => 'tim.hiv@rsnurrohmah.my.id'],
            ['name' => 'Tim KB RS', 'email' => 'tim.kb@rsnurrohmah.my.id'],
            ['name' => 'Tim KMKB', 'email' => 'tim.kmkb@rsnurrohmah.my.id'],
            ['name' => 'Tim Pencegahan Fraud', 'email' => 'tim.fraud@rsnurrohmah.my.id'],
            ['name' => 'Tim Manajemen Komplain', 'email' => 'tim.mjm.komplain@rsnurrohmah.my.id'],
            ['name' => 'Tim PONEK', 'email' => 'tim.ponek@rsnurrohmah.my.id'],
            ['name' => 'Tim Stunting dan Wasting', 'email' => 'tim.stunting@rsnurrohmah.my.id'],
            ['name' => 'Tim Code Blue', 'email' => 'tim.codeblue@rsnurrohmah.my.id'],
            ['name' => 'Tim Hospital Disaster Plan', 'email' => 'tim.hosdip@rsnurrohmah.my.id'],
            ['name' => 'Tim MFK', 'email' => 'tim.mfk@rsnurrohmah.my.id'],
            ['name' => 'Tim Koordinasi Pendidikan Rumah Sakit', 'email' => 'tim.pendidikan@rsnurrohmah.my.id'],
            ['name' => 'Tim Geriatri', 'email' => 'tim.geriatri@rsnurrohmah.my.id'],
            ['name' => 'Panitia Farmasi Terapi', 'email' => 'panitia.farmasi.terapi@rsnurrohmah.my.id'],
            ['name' => 'Panitia Rekam Medis', 'email' => 'panitia.rm@rsnurrohmah.my.id'],
        ];

        foreach ($accounts as $acc) {
            $user = User::firstOrCreate(
                ['email' => $acc['email']],
                [
                    'name' => $acc['name'],
                    // Password bootstrap selalu acak, tidak dicetak, dan tidak disimpan di source.
                    // Operator wajib memakai alur reset password untuk aktivasi akun.
                    'password' => Hash::make(Str::password(40)),
                    'is_active' => true,
                    'unit_id' => null, // Kosongkan sesuai instruksi
                ]
            );

            // Assign role dasar sebagai 'pengusul' dan 'admin_unit'
            $user->syncRoles(['pengusul', 'admin_unit']);
        }
    }
}

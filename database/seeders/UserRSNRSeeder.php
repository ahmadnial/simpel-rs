<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserRSNRSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            // 1. Unit & Instalasi Pelayanan serta Penunjang
            ['name' => 'Unit Asuhan Keperawatan dan Asuhan Kebidanan', 'email' => 'asuhan.keperawatan@rsnurrohmah.my.id', 'password' => 'rsnrNbrn'],
            ['name' => 'Instalasi Rawat Inap', 'email' => 'irna@rsnurrohmah.my.id', 'password' => 'rsnrTP3f'],
            ['name' => 'Instalasi Rawat Jalan', 'email' => 'irj@rsnurrohmah.my.id', 'password' => 'rsnrAbnF'],
            ['name' => 'Instalasi Gawat Darurat', 'email' => 'igd@rsnurrohmah.my.id', 'password' => 'rsnrbmOH'],
            ['name' => 'Instalasi Bedah Sentral', 'email' => 'ibs@rsnurrohmah.my.id', 'password' => 'rsnrnKYa'],
            ['name' => 'Instalasi Hemodialisa', 'email' => 'ihd@rsnurrohmah.my.id', 'password' => 'rsnrXRvj'],
            ['name' => 'Instalasi Kebidanan dan Kandungan', 'email' => 'kebidanan@rsnurrohmah.my.id', 'password' => 'rsnr7uff'],
            ['name' => 'Instalasi Rawat Intensif', 'email' => 'iri@rsnurrohmah.my.id', 'password' => 'rsnr0LYT'],
            ['name' => 'Instalasi NICU dan Perinatologi', 'email' => 'nicu.peri@rsnurrohmah.my.id', 'password' => 'rsnrH8xI'],
            ['name' => 'Unit PKRS', 'email' => 'pkrs@rsnurrohmah.my.id', 'password' => 'rsnrZM1J'],
            ['name' => 'Instalasi Laboratorium', 'email' => 'laboratorium@rsnurrohmah.my.id', 'password' => 'rsnrRcor'],
            ['name' => 'Instalasi Radiologi', 'email' => 'radiologi@rsnurrohmah.my.id', 'password' => 'rsnreogr'],
            ['name' => 'Instalasi Rehabilitasi Medik', 'email' => 'rehabilitasi.medik@rsnurrohmah.my.id', 'password' => 'rsnrNwwm'],
            ['name' => 'Instalasi Farmasi', 'email' => 'farmasi@rsnurrohmah.my.id', 'password' => 'rsnrq6OL'],
            ['name' => 'Instalasi Rekam Medis', 'email' => 'rm@rsnurrohmah.my.id', 'password' => 'rsnrkTkx'],
            ['name' => 'Instalasi Sanitasi', 'email' => 'sanitasi@rsnurrohmah.my.id', 'password' => 'rsnr9NIQ'],
            ['name' => 'Instalasi Gizi', 'email' => 'gizi@rsnurrohmah.my.id', 'password' => 'rsnr0Wob'],
            ['name' => 'Instalasi CSSD', 'email' => 'cssd@rsnurrohmah.my.id', 'password' => 'rsnrtqn6'],
            ['name' => 'Unit SDM', 'email' => 'sdm@rsnurrohmah.my.id', 'password' => 'rsnr2tOy'],
            ['name' => 'Unit Umum, Rumah Tangga dan PSRS', 'email' => 'umum@rsnurrohmah.my.id', 'password' => 'rsnr4Cqp'],
            ['name' => 'Unit IT RS', 'email' => 'itrs@rsnurrohmah.my.id', 'password' => 'rsnrIqK3'],
            ['name' => 'Unit Kamar Jenazah', 'email' => 'kamar.jenazah@rsnurrohmah.my.id', 'password' => 'rsnryn9F'],
            ['name' => 'Unit Pemasaran', 'email' => 'pemasaran@rsnurrohmah.my.id', 'password' => 'rsnrfcgM'],
            ['name' => 'Unit Keuangan', 'email' => 'keuangan@rsnurrohmah.my.id', 'password' => 'rsnrXAdx'],
            ['name' => 'Unit Pembiayaan', 'email' => 'pembiayaan@rsnurrohmah.my.id', 'password' => 'rsnr9G81'],

            // 2. Komite, Tim Khusus, dan Panitia
            ['name' => 'Komite Medis', 'email' => 'komdik@rsnurrohmah.my.id', 'password' => 'rsnraSQH'],
            ['name' => 'Komite Keperawatan', 'email' => 'komite.keperawatan@rsnurrohmah.my.id', 'password' => 'rsnrqNgA'],
            ['name' => 'Komite Tenaga Kesehatan Lain', 'email' => 'komite.naskes.lain@rsnurrohmah.my.id', 'password' => 'rsnrC72q'],
            ['name' => 'Komite Mutu dan Keselamatan Pasien serta Manajemen Risiko', 'email' => 'komite.mutu@rsnurrohmah.my.id', 'password' => 'rsnrFl41'],
            ['name' => 'Komite Etik dan Hukum', 'email' => 'komite.etik@rsnurrohmah.my.id', 'password' => 'rsnrsNLj'],
            ['name' => 'Komite PPI', 'email' => 'komite.ppi@rsnurrohmah.my.id', 'password' => 'rsnrVHWG'],
            ['name' => 'Tim K3', 'email' => 'tim.k3@rsnurrohmah.my.id', 'password' => 'rsnraub5'],
            ['name' => 'Tim PPRA', 'email' => 'tim.ppra@rsnurrohmah.my.id', 'password' => 'rsnr2Ztd'],
            ['name' => 'Tim TB', 'email' => 'tim.tb@rsnurrohmah.my.id', 'password' => 'rsnr26fE'],
            ['name' => 'Tim HIV', 'email' => 'tim.hiv@rsnurrohmah.my.id', 'password' => 'rsnreVVh'],
            ['name' => 'Tim KB RS', 'email' => 'tim.kb@rsnurrohmah.my.id', 'password' => 'rsnrDIq2'],
            ['name' => 'Tim KMKB', 'email' => 'tim.kmkb@rsnurrohmah.my.id', 'password' => 'rsnrAnHT'],
            ['name' => 'Tim Pencegahan Fraud', 'email' => 'tim.fraud@rsnurrohmah.my.id', 'password' => 'rsnrmt9O'],
            ['name' => 'Tim Manajemen Komplain', 'email' => 'tim.mjm.komplain@rsnurrohmah.my.id', 'password' => 'rsnrBGhn'],
            ['name' => 'Tim PONEK', 'email' => 'tim.ponek@rsnurrohmah.my.id', 'password' => 'rsnruKon'],
            ['name' => 'Tim Stunting dan Wasting', 'email' => 'tim.stunting@rsnurrohmah.my.id', 'password' => 'rsnreNo4'],
            ['name' => 'Tim Code Blue', 'email' => 'tim.codeblue@rsnurrohmah.my.id', 'password' => 'rsnr1eoP'],
            ['name' => 'Tim Hospital Disaster Plan', 'email' => 'tim.hosdip@rsnurrohmah.my.id', 'password' => 'rsnrni6J'],
            ['name' => 'Tim MFK', 'email' => 'tim.mfk@rsnurrohmah.my.id', 'password' => 'rsnrDWYl'],
            ['name' => 'Tim Koordinasi Pendidikan Rumah Sakit', 'email' => 'tim.pendidikan@rsnurrohmah.my.id', 'password' => 'rsnrgAAC'],
            ['name' => 'Tim Geriatri', 'email' => 'tim.geriatri@rsnurrohmah.my.id', 'password' => 'rsnrTP9g'],
            ['name' => 'Panitia Farmasi Terapi', 'email' => 'panitia.farmasi.terapi@rsnurrohmah.my.id', 'password' => 'rsnryv1p'],
            ['name' => 'Panitia Rekam Medis', 'email' => 'panitia.rm@rsnurrohmah.my.id', 'password' => 'rsnrlBAr'],
        ];

        foreach ($accounts as $acc) {
            $user = User::firstOrCreate(
                ['email' => $acc['email']],
                [
                    'name' => $acc['name'],
                    'password' => Hash::make($acc['password']),
                    'is_active' => true,
                    'unit_id' => null // Kosongkan sesuai instruksi
                ]
            );

            // Assign role dasar sebagai 'pengusul' dan 'admin_unit'
            $user->syncRoles(['pengusul', 'admin_unit']);
        }
    }
}

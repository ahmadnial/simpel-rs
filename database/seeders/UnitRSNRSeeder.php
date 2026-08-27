<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Str;

class UnitRSNRSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Kosongkan unit dari user agar tidak terjadi error Foreign Key
        User::whereNotNull('unit_id')->update(['unit_id' => null]);
        
        // 2. Putus relasi parent/child pada unit lama
        Unit::whereNotNull('parent_id')->update(['parent_id' => null]);
        
        // 3. Hapus seluruh data unit lama
        Unit::query()->delete();

        // 4. Data Unit Baru
        $units = [
            'Manajemen dan Direksi',
            'Unit Asuhan Keperawatan dan Asuhan Kebidanan',
            'Instalasi Rawat Inap',
            'Instalasi Rawat Jalan',
            'Instalasi Gawat Darurat',
            'Instalasi Bedah Sentral',
            'Instalasi Hemodialisa',
            'Instalasi Kebidanan dan Kandungan',
            'Instalasi Rawat Intensif',
            'Instalasi NICU dan Perinatologi',
            'Unit PKRS',
            'Instalasi Laboratorium',
            'Instalasi Radiologi',
            'Instalasi Rehabilitasi Medik',
            'Instalasi Farmasi',
            'Instalasi Rekam Medis',
            'Instalasi Sanitasi',
            'Instalasi Gizi',
            'Instalasi CSSD',
            'Unit SDM',
            'Unit Umum, Rumah Tangga dan PSRS',
            'Unit IT RS',
            'Unit Kamar Jenazah',
            'Unit Pemasaran',
            'Unit Keuangan',
            'Unit Pembiayaan',
            
            // Komite & Tim
            'Komite Medis',
            'Komite Keperawatan',
            'Komite Tenaga Kesehatan Lain',
            'Komite Mutu dan Keselamatan Pasien serta Manajemen Risiko',
            'Komite Etik dan Hukum',
            'Komite PPI',
            'Tim K3',
            'Tim PPRA',
            'Tim TB',
            'Tim HIV',
            'Tim KB RS',
            'Tim KMKB',
            'Tim Pencegahan Fraud',
            'Tim Manajemen Komplain',
            'Tim PONEK',
            'Tim Stunting dan Wasting',
            'Tim Code Blue',
            'Tim Hospital Disaster Plan',
            'Tim MFK',
            'Tim Koordinasi Pendidikan Rumah Sakit',
            'Tim Geriatri',
            'Panitia Farmasi Terapi',
            'Panitia Rekam Medis',
        ];

        $urutan = 1;
        foreach ($units as $nama) {
            // Generate singkatan (ambil huruf depan tiap kata) atau gunakan nama jika pendek
            $words = explode(' ', $nama);
            $singkatan = '';
            
            // Logika sederhana untuk membuat singkatan unik
            if (count($words) > 1) {
                foreach ($words as $w) {
                    // Hindari kata sambung
                    if (in_array(strtolower($w), ['dan', 'serta'])) continue;
                    $singkatan .= strtoupper(substr($w, 0, 1));
                }
            } else {
                $singkatan = strtoupper(substr($nama, 0, 4));
            }
            
            // Override beberapa singkatan spesifik agar bagus dipandang
            $overrides = [
                'Instalasi Rawat Inap' => 'IRNA',
                'Instalasi Rawat Jalan' => 'IRJ',
                'Instalasi Gawat Darurat' => 'IGD',
                'Instalasi Bedah Sentral' => 'IBS',
                'Instalasi Hemodialisa' => 'IHD',
                'Instalasi Rawat Intensif' => 'IRI',
                'Instalasi Rekam Medis' => 'IRM',
                'Manajemen dan Direksi' => 'MJM',
                'Unit IT RS' => 'IT',
                'Unit SDM' => 'SDM',
                'Unit PKRS' => 'PKRS',
                'Unit Pembiayaan' => 'PMB',
                'Unit Keuangan' => 'KEU',
                'Unit Pemasaran' => 'MKT',
                'Instalasi CSSD' => 'CSSD',
                'Instalasi NICU dan Perinatologi' => 'NICU',
                'Komite Mutu dan Keselamatan Pasien serta Manajemen Risiko' => 'KMKP',
                'Panitia Farmasi Terapi' => 'PFT',
                'Panitia Rekam Medis' => 'PRM',
            ];

            if (isset($overrides[$nama])) {
                $singkatan = $overrides[$nama];
            }

            // Kode unik fallback jika ada singkatan sama
            $kode = $singkatan;
            $count = Unit::where('singkatan', $singkatan)->count();
            if ($count > 0) {
                $kode = $singkatan . ($count + 1);
                $singkatan = $kode;
            }

            Unit::create([
                'kode'      => $kode,
                'singkatan' => $singkatan,
                'nama'      => $nama,
                'is_active' => true,
                'urutan'    => $urutan++,
            ]);
        }
    }
}

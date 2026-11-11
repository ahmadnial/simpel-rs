# QC concurrency SQL Server staging — 11 November 2026

## Hasil

**10 dari 10 skenario lulus** pada database `simpel-dev`, memakai kode backend
workspace terbaru dan dua proses PHP dengan koneksi SQL Server independen.
Tidak ada bug backend baru yang terkonfirmasi pada matriks ini. Tidak diperlukan
restore dari `sivera`; seluruh data uji dibuat sintetis dan dibersihkan sesudahnya.

Pada setiap skenario, `sys.dm_exec_requests` memperlihatkan sesi B diblokir oleh
sesi A dengan wait type **`LCK_M_U`** sebelum A dilepas. Jadi ini pengujian pada
engine dan lock SQL Server nyata, bukan simulasi interleaving SQLite.

## Hasil per skenario

| A mendapat lock dahulu | B mencoba saat A memegang lock | Hasil B | Status akhir |
| --- | --- | --- | --- |
| Approve, kuorum 1 | Approve pemeriksa lain | Ditolak 409 | menunggu_ttd |
| Approve, kuorum 1 | Minta revisi | Ditolak 409 | menunggu_ttd |
| Minta revisi | Approve | Ditolak 409 | revisi |
| Approval pertama, kuorum 2 | Approval kedua | Berhasil; dua aktor berbeda | menunggu_ttd |
| Approval parsial, kuorum 2 | Minta revisi | Berhasil | revisi |
| Submit receipt A | Submit receipt B untuk versi sama | Ditolak 403 | diajukan |
| Upload pengganti | Submit memakai receipt sumber lama | Ditolak 422 | draft |
| Submit | Upload pengganti | Ditolak 409 | diajukan |
| Signer mengembalikan | Approve memakai tiket lama | Ditolak 409 | ditolak_penandatangan |
| Signer mengembalikan | Pengembalian signer kedua | Ditolak 403 | ditolak_penandatangan |

Semua aksi A berhasil. Pemeriksaan tambahan memastikan:

- Tidak ada tiket aktif duplikat atau peer menunggu yang tersisa setelah keputusan
  terminal; pengembalian signer membuat tepat satu putaran baru dengan dua tiket.
- Keputusan lama tidak berubah setelah pengembalian atau replay.
- Double submit tidak menimpa snapshot pertama, tidak menyisakan snapshot orphan,
  dan hanya mencatat satu audit pengajuan.
- Hash sumber tetap sama pada semua kasus yang tidak mengunggah pengganti.
  Upload yang berhasil menyimpan sumber berbeda dan membersihkan sumber draft
  lama setelah commit; upload yang kalah tidak mengubah sumber yang diajukan.
- Tidak ada versi tambahan yang tidak semestinya pada pasangan submit/upload.

## Keamanan lingkungan dan data

Target dipaksa menjadi `simpel-dev` sebelum bootstrap; config cache dan storage
dipisahkan dalam direktori temporary privat. Harness memverifikasi `DB_NAME()` dan
menolak switching database/SQL yang menyebut production. `.env` asli tidak diubah.
Tidak ada aplikasi atau konfigurasi production yang dideploy/direload.

Skema staging tertinggal, sehingga tiga migration aplikasi dijalankan **hanya di
`simpel-dev`**: penambahan `otp_email`, pelebaran teks audit/reason, dan migration
koreksi indeks SQLite yang tidak melakukan DDL pada SQL Server. Tidak ada restore,
drop database, truncate, `migrate:fresh`, atau penyalinan berkas dokumen production.

Setelah cleanup, fingerprint SHA-256 dan jumlah baris pada **16 tabel** sama dengan
baseline sesudah penyelarasan schema. Termasuk 14 dokumen, 16 versi, 79 tiket,
56 user asli staging, serta audit/notifikasi dan audit chain yang diperiksa.
Identity counter dapat maju karena pembuatan fixture; tidak di-reset.

Semua sumber/PDF untuk test berada di storage temporary lokal; tidak ada akses ke
berkas dokumen production. Notifikasi workflow menggunakan channel database pada
staging dan dibersihkan bersama fixture. Tidak ada email OTP yang dikirim.

## Reproduksi dan batas bukti

Harness: `tests/Concurrency/sqlserver-workflow.php`; guard dan isolasi:
`tests/Concurrency/bootstrap.php`. Jalankan sesuai `tests/Concurrency/README.md`.
Bukti final: `backend-fix-2026-11-11/sqlserver-concurrency-qc.json`.

Regresi lokal sesudah penambahan harness/guard: **184 tests, 1.430 assertions,
0 error, 0 failure**. Tiga tes baru menolak target production, flag yang hilang,
dan eksekusi tanpa opt-in sebelum bootstrap database.

Pengujian memakai PHP/driver lokal untuk mengeksekusi kode workspace terhadap
SQL Server staging pada VM; bukan deployment web staging. Controller/service
dipanggil langsung dengan konteks request/auth sintetis. Status sukses pada JSON
dinormalisasi menjadi 200 untuk pemanggilan method, bukan klaim HTTP wire 200.
PDF memakai test double untuk memusatkan uji pada transaksi dan integritas byte;
konversi LibreOffice nyata sudah diuji terpisah pada QC backend sebelumnya.

Matriks ini membuktikan sepuluh interleaving terkontrol, bukan bebas deadlock untuk
semua pola load. Penandatanganan nyata versus pengembalian, fault jaringan/commit
ambigu, load berkepanjangan, layanan signing eksternal, dan QC browser melalui
deployment Nginx staging belum tercakup oleh run ini. Rollout MIME worker production
dan pemulihan data/berkas production tetap belum dilakukan.

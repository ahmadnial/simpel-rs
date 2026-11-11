# Pemulihan 500 sesudah pull — 12 November 2026

**Pulih:** `/login` dan `/up` HTTP 200; Chrome menampilkan form login dan field
password tanpa JavaScript error. Tidak dilakukan login sebagai pengguna atau
transaksi dokumen production.

## Penyebab terkonfirmasi

Saat diperiksa sekitar 06:18 WIB, Git VM sudah berada pada `ae2627e` dan working
tree tracked bersih. File kode baru dapat dibaca `www-data`; maintenance nonaktif.
`bootstrap/cache/config.php` tidak ada. `.env` tetap mode 0600, owner akun
deployment, sehingga tidak dapat dibaca PHP-FPM.

Akibatnya proses web tidak memperoleh `APP_KEY`. Log mencatat
`Illuminate\Encryption\MissingAppKeyException` dengan pesan
`No application encryption key has been specified.` Health route masih 200,
sedangkan halaman login 500. Karena itu health route saja tidak cukup untuk
menilai pemulihan halaman yang memakai session/encryption.

Perintah yang menghilangkan cache belum diketahui. Source Laravel yang terpasang,
`Illuminate\Foundation\ComposerScripts::clearCompiled`, dapat menghapus config
cache melalui `post-autoload-dump`; Composer atau clear-cache perlu diikuti
pembuatan cache kembali. Ini bukti perilaku framework, bukan klaim perintah
tertentu telah dijalankan pengguna.

## Pemulihan yang diterapkan

- Validasi `.env` sebagai akun deployment: key sudah ada, environment production,
  database `sivera`. Nilai rahasia tidak ditampilkan.
- Jalankan `php artisan config:cache` sebagai akun deployment. Cache menggunakan
  key yang sama persis dengan `.env`; tidak menjalankan `key:generate`.
- Batasi cache ke owner deployment dan grup `www-data`, mode 0640. Web server
  dapat membaca cache tanpa memperoleh akses baca langsung ke `.env`.
- Verifikasi konfigurasi: production, debug nonaktif, SQL Server `sivera`, tiga
  key runtime QR tersedia, key cocok dengan `.env` yang sudah ada.
- Verifikasi HTTP `/up` dan `/login` 200, dilanjutkan Chrome tanpa submit form:
  form login dan field password tampil, tidak ada JavaScript error.

Usulan awal mengubah izin `.env` ditolak pemeriksaan persetujuan otomatis karena
memperluas akses ke semua rahasianya. Usulan itu tidak diterapkan. Alternatif
yang digunakan hanya memulihkan mekanisme config cache production sebelumnya.
Isi, owner/group, dan mode `.env` tidak berubah. Kode tracked dan HEAD Git VM juga
tetap; tidak ada perubahan schema atau data bisnis dokumen.

## Koreksi prosedur rilis

Panduan sebelumnya kurang tegas karena menyebut rebuild cache sebagai langkah
opsional. Dukungan fallback untuk key QR pada cache lama tidak menggantikan
kebutuhan `APP_KEY` dari konfigurasi production.

Sekarang [`deploy/README.md`](../../deploy/README.md) mewajibkan urutan:
seluruh operasi Git/Composer/clear-cache → `config:cache` sebagai deployment user
→ pastikan group dan izin cache sesuai → baru `artisan up`. Jangan menjalankan
Composer atau clear-cache setelahnya tanpa membangun cache kembali. `.env`
production tetap privat dan tidak perlu diganti.

Bukti tersanitasi:
[config-cache-recovery.json](vm-readiness-2026-11-12/config-cache-recovery.json).

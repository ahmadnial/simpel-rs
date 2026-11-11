# Persiapan VM selesai; aplikasi menunggu deployment Git — 12 November 2026

**Server siap untuk tahap rilis perbaikan bertahap melalui Git. Push/pull dan
deployment kode aplikasi tidak dijalankan oleh agen.**

Persetujuan pengguna mencakup instalasi runtime PDF/QR, batas upload, konfigurasi
Nginx/PHP-FPM, validasi, dan reload layanan. Database production dan kode aplikasi
VM tidak diubah. Keterbatasan format Word yang sudah dilaporkan tetap berlaku.

## Perubahan server yang selesai

1. Lima paket baru terpasang: `poppler-utils`, `python3-venv`, `python3.12-venv`,
   `python3-pip-whl`, dan `python3-setuptools-whl`. Tidak ada paket lama yang
   di-upgrade atau dihapus. Restart otomatis paket dibatasi ke mode daftar.
2. `/var/www/apps/simpel-rs/.venv-pdf` berisi Python **3.12.3** dan **pypdf 7.0.1**,
   dapat dipakai oleh `www-data`. `pdftotext`/`pdftoppm` **24.02.0** juga berhasil
   dipanggil oleh pengguna tersebut.
3. Vhost HTTPS SIMPEL memuat `/etc/nginx/snippets/simpel-pdf-worker.conf`.
   Worker `.mjs` kini HTTP 200 dengan `Content-Type: application/javascript`;
   aset yang tidak ada tetap 404. Chrome berhasil menjalankan module worker
   sampai menerima pesan `ready`, tanpa intersepsi MIME oleh tes.
4. SIMPEL memakai pool `[simpel]` pada
   `/etc/php/8.4/fpm/pool.d/simpel.conf`, socket
   `/run/php/php8.4-fpm-simpel.sock`. Batas upload **10M**, POST **16M**, process
   manager `ondemand`, maksimum lima child. Template berada di
   [`deploy/php-fpm/simpel.conf`](../../deploy/php-fpm/simpel.conf).
5. `nginx -t` dan `php-fpm8.4 -t` lulus sebelum graceful reload. Nginx dan FPM
   tetap aktif; HTTPS `/up` merespons 200 setelah perubahan.

Empat vhost awal menggunakan pool `www` yang sama. Percobaan override upload
melalui `PHP_VALUE` Nginx ditolak setelah QC mendeteksi setting menetap pada
worker bersama yang dipakai ulang. Override itu sudah dihapus dan worker
dipulihkan lewat reload. Solusi akhir menggunakan pool SIMPEL tersendiri;
`php.ini` dan konfigurasi pool `www` tetap identik dengan cadangan awal.
Pool `www` terverifikasi tetap **2M/8M** setelah seluruh pengujian.

Dokumentasi PHP mengenai konfigurasi pool dan FastCGI tersedia pada
[PHP-FPM configuration](https://www.php.net/manual/en/install.fpm.configuration.php).
Perilaku worker aktual di VM, bukan asumsi konfigurasi saja, menentukan solusi
yang diterapkan.

## QC nyata pada VM

Probe privat berada di `/tmp`, menggunakan Nginx terisolasi pada loopback dan
socket pool yang disalin dari konfigurasi HTTPS aktif. Probe tidak memuat
Laravel atau menjalankan transaksi dokumen. Tidak dibuat endpoint diagnostik
pada aplikasi atau vhost publik. Semua probe dan proses Nginx uji dibersihkan.

| Pemeriksaan | Hasil |
| --- | --- |
| Upload sintetis 3 MiB | Diterima, `UPLOAD_ERR_OK`, ukuran sesuai |
| Upload sintetis tepat 10 MiB | Diterima, `UPLOAD_ERR_OK`, ukuran sesuai |
| Upload 10 MiB + 1 byte | Ditolak PHP, `UPLOAD_ERR_INI_SIZE` |
| Pool `www` sebelum/sesudah | Tetap 2M upload / 8M POST |
| LibreOffice dari PHP-FPM UID 33 | DOCX sintetis berhasil menjadi PDF |
| Python/pypdf + Poppler dari PHP-FPM tanpa PATH | Berhasil; executable absolut |
| Penambahan QR ke PDF | QR dapat dipindai; selisih koordinat teks 0 pt |
| Worker PDF.js di Chrome pada origin production | Module worker `ready` |
| Worker statis hilang | HTTP 404 |
| Health production setelah reload | HTTP 200 |

Ini membuktikan kesiapan runtime, batas upload, dan perbaikan MIME. Ini belum
membuktikan setiap dokumen production atau alur aplikasi versi baru, karena kode
baru belum dideploy dan tidak dibuat transaksi dokumen production.

## Paket kode lokal dan kompatibilitas deployment

QC lokal terakhir: **200 tes aplikasi / 1.521 assertion**, **11 tes integrasi
Office dan concurrency / 288 assertion**, serta **10 tes Python** lulus.
Satu tes penerimaan Word hanya aktif bila referensinya diberikan; perbedaan
101 vs 100 halaman tetap merupakan keterbatasan yang diketahui.

Cache konfigurasi VM lama memang belum memiliki tiga key QR baru. Kode lokal
`DocumentQrOverlay` sekarang memakai default yang sama dengan `config/documents.php`
jika key tersebut belum ada. Dua tes HTTP terpisah—konfigurasi terkini dan
konfigurasi rilis lama tanpa key QR—lulus, total 36 assertion, termasuk QR scan
dan koordinat PDF. Path yang secara eksplisit salah tetap ditolak; fallback
tidak menyembunyikan konfigurasi salah.

Route cache VM nonaktif, Composer classmap tidak authoritative, dan seluruh
22 compiled views yang ditemukan dapat ditulis oleh `www-data`. Dengan default
runtime VM yang telah disiapkan, tidak ada kebutuhan mengedit `.env`, mengosongkan
cache aplikasi/database, membangun frontend, atau menjalankan migration SQL Server
untuk menyiapkan perubahan ini. Rebuild cache melalui prosedur deployment normal
tetap dapat dilakukan setelah kode tersedia.

Folder dokumen contoh asli dan virtual environment dikecualikan dari Git. Tidak
ada DOCX/PDF contoh atau kredensial yang dimasukkan ke paket kode.

## Keadaan Git dan langkah operator

Sebelum deployment, VM tetap pada `1ff6d0096096a1cbbe97fb2bcbe26369a792ac44` dengan
satu perubahan lokal pada `app/Services/DocxTemplateProcessor.php`.
Isi file lokal VM tersebut **identik** dengan versi yang sudah berada pada
commit dasar rilis `9e1d1991c6b1120ac314a507045f79420e127667`. Perbaikan relationship
libzip itu dipertahankan dan diperluas pada paket baru. Akses baca remote main
berhasil dari laptop dan dari akun deployment VM. Tidak ada push/pull dilakukan.

Simpan perubahan lokal VM melalui Git sebelum pull agar tidak ditolak sebagai
working tree yang kotor. Urutan operator ada di
[`deploy/README.md`](../../deploy/README.md). Stash hotfix lama tidak perlu
diterapkan ulang setelah pull sukses karena perbaikannya sudah terdapat dalam
rilis. Jangan menghapus stash/cadangan sebelum memvalidasi rilis.

Sesudah deployment Git, uji browser dengan dokumen uji yang disetujui:
upload → preview → Ajukan aktif → revisi → upload pengganti → preview versi baru
→ ajukan ulang. Perbaikan MIME sudah terbukti hidup; kelulusan seluruh alur kode
baru tetap harus dinilai setelah kode baru benar-benar tersedia.

## Cadangan dan integritas

Cadangan konfigurasi, snapshot daftar paket, salinan hotfix, dan patch Git berada
di direktori root-only:
`/var/backups/simpel-vm-prep-20261111T185753Z`.

Fingerprint `.env`, kode processor yang sedang berjalan, config cache, dan
manifest frontend VM tetap sama sebelum/sesudah pekerjaan ini. Commit serta
status tracked Git VM juga tetap sama. Tidak ada query database eksplisit,
mutasi data bisnis, restore database, push/pull, atau deployment aplikasi.

Untuk rollback konfigurasi server, kembalikan vhost awal dari cadangan dan
validasi Nginx sebelum reload. Setelah vhost kembali memakai socket `www`, pool
`simpel` dapat dilepas dengan validasi dan reload FPM. Jangan melepas pool sebelum
vhost dipindahkan dari socket-nya. Runtime Python/Poppler dapat dibiarkan terpasang;
tidak diperlukan uninstall untuk mengembalikan routing server.

Bukti tersanitasi:
[prepared.json](vm-readiness-2026-11-12/prepared.json).

# Kesiapan VM untuk rilis perbaikan bertahap — 12 November 2026

**Tindak lanjut:** persiapan server di bawah telah dilaksanakan dan diverifikasi.
Lihat [hasil persiapan VM](2026-11-12-production-vm-prepared.md). Isi laporan ini
adalah snapshot sebelum perubahan, bukan status akhir server.

**Keputusan: belum siap untuk langsung pull/deploy seluruh perubahan.**
Pemeriksaan dilakukan melalui SSH pada 12 November sekitar 01:45–01:49 WIB.
Tidak ada instalasi, perubahan konfigurasi, reload layanan, deployment aplikasi,
atau query database eksplisit. Request HTTP hanya health check dan aset statis.

Pemeriksaan ini menilai rilis bertahap dengan keterbatasan formatting yang sudah
diketahui; bukan sertifikasi bahwa semua DOCX menghasilkan PDF identik Word.

## Penghambat yang terkonfirmasi

| Area | Bukti VM | Akibat jika hanya deploy kode | Yang diperlukan |
| --- | --- | --- | --- |
| Worker PDF.js | Worker `.mjs` HTTP 200, `Content-Type: application/octet-stream` | Module worker masih dapat ditolak browser; preview tidak selesai dan tombol Ajukan tetap disabled | Pemetaan MIME JavaScript pada vhost HTTPS, validasi lalu reload Nginx, cek ulang HTTP dan browser |
| Poppler | `pdftotext` dan `pdftoppm` tidak ada di PATH maupun `/usr/bin`/`/usr/local/bin`; paket `poppler-utils` belum terpasang | Overlay QR baru gagal; preview berpotensi 503 | Pasang Poppler dan verifikasi executable dapat dipanggil oleh pengguna aplikasi |
| Python PDF | Python 3.12.3 ada, tetapi `ensurepip`, paket venv, dan `.venv-pdf/bin/python` aplikasi belum tersedia | Runtime `pypdf` yang diwajibkan kode baru belum siap | Pasang tooling venv, buat environment aplikasi, install `scripts/requirements-pdf.txt` (`pypdf==7.0.1`), uji sebagai pengguna aplikasi |
| Batas upload | Konfigurasi FPM: `upload_max_filesize=2M`, `post_max_size=8M`; validasi aplikasi mengizinkan 10 MiB | DOCX lebih dari 2 MiB tetap terhalang sebelum validasi/alur backend baru | Selaraskan, misalnya upload 10M dan POST 16M; validasi FPM dan reload setelah disetujui |
| Cache konfigurasi | Cache `documents` belum memiliki `pdf_python`, `pdftotext_path`, `pdftoppm_path` | Setelah kode baru naik, nilai konfigurasi QR masih kosong meskipun dependency sudah disiapkan | Bangun ulang config cache memakai environment production setelah seluruh file rilis tersedia |

Poppler dan Python merupakan satu kelompok kesiapan runtime QR, sehingga ringkasan
operasional menyebut empat kelompok penghambat: MIME, runtime QR, upload, cache.

Batas upload telah diperiksa memakai binary PHP 8.4 dengan `php.ini` dan seluruh
`conf.d` FPM, dijalankan sebagai UID 33 (`www-data`). Pool yang dibaca tidak
menambahkan override upload. Ini memverifikasi konfigurasi di disk; tidak dibuat
endpoint `phpinfo` atau request upload production untuk mengukur proses FPM hidup.
Nginx mengizinkan body 64M sehingga pada konfigurasi yang dibaca batas 2M berasal
dari PHP, bukan dari batas body Nginx.

## Bagian yang siap pada pemeriksaan ini

- Nginx dan `php8.4-fpm` aktif; HTTPS `/up` HTTP 200.
- `nginx -t` berhasil dengan autentikasi sudo. Percobaan awal `sudo -n` tidak
  memiliki autentikasi; itu bukan temuan kesalahan syntax Nginx.
- PHP 8.4.27 memenuhi `^8.4`; pemeriksaan platform Composer lulus.
- Ekstensi SQL Server, ZIP, DOM, mbstring, GD, Imagick, curl, OpenSSL, dan fileinfo
  tersedia pada konfigurasi FPM yang diuji. `proc_open` callable dan
  `disable_functions` kosong pada konfigurasi tersebut.
- `storage`, `storage/app/private`, `pdf_cache`, `temp_processed`, dan
  `bootstrap/cache` dapat dibaca/ditulis oleh `www-data`. Akses ini diperiksa
  melalui metadata; tidak dibuat file uji pada storage production.
- LibreOffice tersedia. Audit sebelumnya mengidentifikasi versi VM 24.2.7.2.
- Disk bebas sekitar 11,11 GiB, inode bebas 1.589.859, RAM tersedia sekitar
  14,2 GiB, enam CPU dengan load sekitar 0,1 saat snapshot. Ini bukan uji beban.
- `.env` dan config cache sama-sama menunjuk environment `production`, debug
  nonaktif, SQL Server database `sivera`. Nilai rahasia tidak dicetak atau disalin
  ke laporan. Tidak ada koneksi SQL eksplisit untuk pemeriksaan ini.

## Hal penting untuk penyusunan rilis

Root aplikasi `/var/www/apps/simpel-rs`, branch `main`, commit
`1ff6d0096096a1cbbe97fb2bcbe26369a792ac44`. Ada perubahan lokal pada
`app/Services/DocxTemplateProcessor.php` (17 baris tambahan, 11 dihapus).
Perubahan tersebut menampung relationship dalam DOM dan menulis setiap ZIP part
sekali agar kompatibel dengan libzip lama, disertai invalidasi cache template.
Implementasi workspace saat ini sudah mempertahankan pola itu. Working tree VM
tetap harus direkonsiliasi saat deployment; tidak dilakukan reset atau stash.

`composer.json`, `composer.lock`, dan manifest aset build VM identik hash dengan
workspace. Tidak ditemukan kebutuhan upgrade dependency PHP atau perubahan build
worker frontend untuk perbaikan ini. File layanan/skrip QR dan kompatibilitas style
yang baru memang belum ada pada VM; paket rilis harus mencakup seluruh file baru,
bukan hanya file yang sebelumnya sudah terlacak Git.

Satu migration yang hanya ada di workspace memperbaiki indeks **SQLite** dan
langsung return pada SQL Server. Temuan ini tidak menjadi alasan mengubah indeks
atau menjalankan reset schema database `sivera`.

`.env` tidak dapat dibaca oleh `www-data`, sedangkan aplikasi saat ini berjalan
dengan config cache. Jangan mengosongkan config cache lalu meninggalkan aplikasi
berjalan dalam kondisi itu. Susun cache melalui akun deployment yang dapat
membaca `.env` production; jangan menyalin `.env` laptop yang memakai `simpel-dev`.
Tidak ada perubahan permission `.env` yang dilakukan atau diwajibkan oleh audit.

## Urutan minimum sebelum membuka rilis

1. Siapkan backup rilis/config dan rekonsiliasi perubahan lokal VM; siapkan paket
   kode lengkap. Tidak ada backup/restore yang dinyatakan teruji oleh audit ini.
2. Siapkan runtime QR dan cocokkan executable/pypdf dengan konfigurasi rilis.
3. Selaraskan batas upload PHP dengan batas aplikasi. Validasi konfigurasi FPM.
4. Terapkan kode lengkap, bangun config cache dari environment production dengan
   akun deployment, serta tangani compiled views sesuai prosedur deployment.
   Hindari penghapusan cache aplikasi/database secara luas yang tidak diperlukan.
5. Terapkan [snippet MIME worker](../../deploy/nginx/README.md), jalankan validasi
   Nginx, dan reload layanan terkait hanya dalam deployment yang disetujui.
6. Verifikasi worker HTTP 200 dengan MIME JavaScript, worker hilang tetap 404,
   runtime QR dapat menghasilkan PDF yang bisa dipindai sebagai pengguna web,
   dan dokumen sah di atas 2 MiB dapat melewati batas server sampai batas aplikasi.
7. Uji browser: upload → preview berhasil → Ajukan aktif → verifikator minta revisi
   → upload pengganti → preview versi baru → ajukan ulang. Gunakan akun/dokumen uji
   yang disetujui; pemeriksaan production ini tidak membuat transaksi tersebut.

Tombol disabled **belum dinyatakan pulih di production**. Kode workflow sudah
memiliki hasil QC sebelumnya, tetapi bukti HTTP saat ini masih menunjukkan akar
masalah MIME worker. Kelulusan tes PHP tidak menggantikan verifikasi browser
melalui vhost production yang sebenarnya.

Masalah formatting 101 vs 100 halaman dan pergeseran halaman 11 tetap tercatat
dalam [laporan formatting](2026-11-12-table-style-fix-qc.md). Persiapan VM tidak
menyelesaikan atau menyembunyikan batas tersebut.

Bukti pemeriksaan tersanitasi:
[readiness.json](vm-readiness-2026-11-12/readiness.json).

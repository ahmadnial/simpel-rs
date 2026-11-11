# Investigasi pengajuan dan revisi dokumen production — 11 November 2026

## Kesimpulan dan batas pemeriksaan

Dua penghalang pengajuan terverifikasi di production: PDF worker disajikan dengan MIME yang salah, dan form pembuatan dokumen melempar TypeError untuk pengguna tanpa unit. Satu draft juga merujuk berkas sumber yang tidak ditemukan oleh akun web server. Tidak ada perubahan aplikasi, konfigurasi, permission, data production, atau deployment selama investigasi. Pemeriksaan menggunakan SELECT terbatas, pembacaan berkas/log, dan HEAD/GET aset statis tanpa login. Tidak ada percobaan upload atau submit terhadap production.

Tool Bughunter ditinjau, tetapi kontraknya khusus riset keamanan/bug bounty. Tool tersebut tidak dijalankan untuk gangguan workflow ini. Diagnosis menggunakan bukti database, VM, kode, dan browser.

## 1. Akar kegagalan viewer yang membuat tombol Ajukan tetap nonaktif

Bukti HTTP production:

| Aset | HTTP | Content-Type |
| --- | --- | --- |
| `/build/assets/app-BjUPZV99.js` | 200 | `application/javascript` |
| `/build/assets/pdf-preview-viewer-D8QcKiA6.js` | 200 | `application/javascript` |
| `/build/assets/pdf.worker.min-FHbmGBN0.mjs` | 200 | **`application/octet-stream`** |

Chrome aktual, pada origin production dan hanya memakai aset statis, gagal mengimpor worker dengan pesan:

> Failed to load module script: Expected a JavaScript-or-Wasm module script but the server responded with a MIME type of "application/octet-stream".

Rantai sebab:

1. `resources/js/pdf-preview-viewer.js:2` mengimpor URL worker `.mjs`; baris 4 menetapkan `GlobalWorkerOptions.workerSrc`.
2. PDF.js memuat worker sebagai module. Jalur fallback fake worker juga memakai dynamic import, sehingga MIME yang sama tetap menghalanginya.
3. Nginx vhost production tidak memiliki pemetaan `.mjs` tersendiri; respons aktual salah MIME.
4. Viewer tidak berhasil merender halaman pertama. `onReady(true)` tidak tercapai.
5. `resources/js/document-preview.js:25` mempertahankan tombol `[data-document-submit]` dalam keadaan disabled sampai receipt PDF diberikan setelah render berhasil. Jalur error membersihkan receipt lagi.

Ini memengaruhi pengajuan pertama maupun pengajuan ulang sesudah revisi. Mengunggah berkas pengganti tidak memperbaiki header worker, sehingga pengguna dapat berhasil upload tetapi tetap tidak bisa mengajukan. Belum ada sesi browser pengguna terdampak yang diperiksa; kegagalan worker dan jalur kode tombol sudah dibuktikan secara terpisah.

Kode controller, policy, view detail, dan JavaScript preview di VM memiliki SHA-256 sama dengan workspace. Manifest build juga sama. VM berada pada commit `1ff6d00` dengan modifikasi lokal pada `DocxTemplateProcessor.php`; workspace berada pada `9e1d199`. Karena bagian alur yang dibandingkan identik, ketidaksesuaian kode tombol bukan penjelasan untuk temuan MIME ini. Modifikasi lokal VM harus dipertahankan pada deployment berikutnya.

### Perbaikan yang disiapkan untuk ditinjau, belum diterapkan

Tambahkan pemetaan MIME terbatas pada aset build dalam server block HTTPS aplikasi `/etc/nginx/sites-available/simpel-rs`, dengan penempatan regex location yang sesuai:

```nginx
location ~ ^/build/.*\.mjs$ {
    types { application/javascript mjs; }
    try_files $uri =404;
}
```

Setelah perubahan production disetujui, operator perlu memvalidasi `nginx -t`, melakukan reload, lalu memastikan header worker menjadi JavaScript. Jangan menghapus `disabled` atau melewati validasi `review_token`: itu menghilangkan kontrol pemeriksaan versi tanpa memperbaiki viewer.

Verifikasi HTTP yang dapat diulang tanpa login:

```sh
curl -I https://simpel.rsnurrohmah.my.id/build/assets/pdf.worker.min-FHbmGBN0.mjs
```

Kriteria penerimaan: MIME JavaScript, module worker berhasil dimuat di browser, PDF halaman pertama tampil, dan tombol Ajukan aktif setelah receipt sah. Pengujian submit harus memakai akun/dokumen uji yang disetujui, tidak memakai dokumen production secara sembarang.

## 2. Akun tanpa unit gagal membuka form dokumen

Log VM mencatat `DocumentService::getFirstStepInfo(): Argument #2 ($unitId) must be of type int, null given`, termasuk 10 November 2026 pukul 07:22:28 menurut timestamp log.

`DocumentController::create()` mengambil `auth()->user()->unit_id` lalu meneruskannya ke `getFirstStepInfo(DocumentType $documentType, int $unitId)`. Tidak ada penanganan unit kosong sebelum pemanggilan. SELECT production menemukan **7 akun aktif dengan `unit_id IS NULL`**. Ini bukan bukti ketujuhnya pernah mencoba mengajukan, tetapi ketujuhnya memenuhi kondisi pemicu jika masuk ke jalur tersebut.

Perbaikan yang diperlukan: tangani unit kosong secara eksplisit sebelum resolusi workflow, tampilkan pesan yang dapat ditindaklanjuti; konfirmasi penempatan unit yang benar jika akun itu memang pengusul. Jangan menetapkan unit default atau memperbarui seluruh akun secara massal. Skema dokumen juga mensyaratkan `unit_id`, sehingga sekadar mengubah tipe parameter menjadi nullable tidak menyelesaikan kebutuhan unit dokumen.

## 3. Satu draft kehilangan sumber pada lokasi yang direferensikan

Pemeriksaan sebagai **www-data** menunjukkan:

- 71/71 dokumen revisi: sumber tersedia dan pemeriksaan variabel template berhasil, tanpa variabel tidak dikenal.
- 7/8 draft: sumber tersedia dan pemeriksaan template berhasil.
- Draft internal ID **10231**: berkas sumber pada `document_versions.file_path` tidak ditemukan.

`DocumentService::ensureDocxFileExists()` menghentikan preview dengan 422 jika sumber tidak ada. Perbaikan MIME saja tidak memulihkan draft ini. Periksa backup dan riwayat storage, lalu pulihkan sumber yang benar atau minta pemilik mengunggah ulang. Belum dilakukan pencarian backup; penyebab awal kehilangan sumber belum diketahui.

Catatan metodologi: pemeriksaan awal memakai akun SSH tidak dapat menelusuri direktori dokumen mode 2700 milik www-data, sehingga menghasilkan false missing. Hasil tersebut dibatalkan dan pemeriksaan diulang sebagai www-data. Permission production tidak diubah.

## Keadaan database yang teramati

Saat query identitas awal, SQL Server melaporkan `2026-11-11 12:46:03 +00:00`. Query berikutnya berjalan terpisah dengan lock timeout 3 detik dan query timeout terbatas; angka merupakan observasi berurutan, bukan snapshot atomik.

| Status | Dokumen | Pengusul unik dalam status |
| --- | ---: | ---: |
| revisi | 71 | 22 |
| draft | 8 | 6 |
| diajukan | 10 | 7 |
| dalam_verifikasi | 27 | 9 |
| menunggu_ttd | 33 | 7 |

- Seluruh 149 dokumen nondeleted mempunyai tepat satu versi aktif.
- Seluruh 71 dokumen revisi memiliki pengusul aktif dan workflow terkait aktif, tanpa tiket `menunggu` tersisa. Tidak ada indikasi tiket menunggu yang belum ditutup mengunci revisi ini.
- Pada 19 dokumen revisi dari 12 pengusul, `currentVersion.created_at` lebih baru daripada waktu keputusan revisi terakhir. Pada 52 lainnya tidak demikian. Kelompok pengusul dapat beririsan; angka ini tidak mengukur berapa orang telah mencoba upload dan gagal.
- Audit 14 hari terakhir memuat 12 `upload_versi`, 4 `ganti_berkas_draft`, dan tidak memuat `ajukan` dalam agregasi yang diperiksa. Ini konsisten dengan hambatan sesudah upload, tetapi tidak menggantikan bukti sesi per pengguna.
- Seluruh 350 versi memiliki `file_pdf_path` NULL. Ini konsisten dengan belum adanya snapshot yang berhasil disimpan oleh alur pengajuan baru; riwayat deployment dan data lama perlu dipertimbangkan sebelum menafsirkan keseluruhannya.

## Upload ulang: yang terbukti dan yang belum

Policy update mengizinkan pemilik untuk status `draft` atau `revisi`. Tombol `Unggah dokumen` pada view tidak memiliki atribut disabled awal dan tidak memakai atribut `data-document-submit`. JavaScript menonaktifkannya setelah event submit untuk mencegah upload ganda. Tombol Ajukan adalah tombol berbeda yang bergantung pada preview.

Belum direproduksi bahwa tombol **Unggah dokumen itu sendiri** tetap nonaktif sebelum submit pada sesi pengguna nyata. Jika keluhan ini bertahan setelah MIME diperbaiki, rekam label tombol, status dokumen, response upload, dan perilaku sesudah back/forward tanpa mengubah data pengguna. Jangan menganggap bukti kegagalan viewer menjelaskan seluruh kemungkinan kegagalan upload.

Pengembalian penandatangan ke `ditolak_penandatangan` juga berbeda dari `revisi`: policy tidak mengizinkan edit pemilik pada status itu karena alurnya membuka pemeriksaan ulang verifikator. Tidak ada dokumen nondeleted dalam status tersebut saat pemeriksaan.

## Verifikasi lokal dan keterbatasan tes

Dijalankan:

```sh
php vendor/bin/phpunit --do-not-cache-result \
  tests/Feature/DocumentUploadPreviewTest.php \
  tests/Unit/SqlServerForeignKeyCastTest.php
```

Hasil: **15 tests, 180 assertions, lulus**. Pengujian memakai SQLite `:memory:` dengan config cache testing terpisah dan fake storage; tidak menjalankan migrasi pada production. Mencakup upload, perubahan versi setelah revisi, pengajuan ulang, receipt kedaluwarsa/berubah, serta cast foreign key SQL Server sintetis.

Tes browser yang sudah ada (`tests/browser/document-preview.cjs`) mengintersep request aset dan mengembalikan semua aset non-CSS sebagai `text/javascript`. Akibatnya tes tersebut menutupi konfigurasi MIME Nginx dan tidak dapat menangkap kegagalan production ini. Tambahkan smoke check header HTTP aset build ke verifikasi deployment, serta tes akun tanpa unit.

Tidak ada upload, submit, perubahan DB, pemulihan sumber, perubahan Nginx, reload service, atau deploy dilakukan. Laporan ini adalah hasil diagnosis dan usulan remediasi, bukan klaim gangguan sudah diperbaiki.

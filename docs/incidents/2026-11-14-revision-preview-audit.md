# Audit alur revisi dan pratinjau — 14 November 2026

## Kesimpulan dan batas bukti

Audit menemukan celah pemulihan preview dan inkonsistensi alur revisi yang dapat direproduksi. Untuk kasus **dokumen 10112**, ada bukti kuat pengiriman PDF tidak lengkap pada beberapa request 13 November; penyebab penghentian transfer belum dapat ditentukan. Tidak ada bukti yang cukup untuk menyebut konverter, format DOCX, atau MIME worker sebagai akar masalah kasus ini.

VM dan lokal berada pada commit `8ab81dd913af62547d4b2b1f9eff4dad39ca1b07`; working tree tracked VM bersih. Pemeriksaan production hanya SELECT, pembacaan berkas/config/log dan HEAD aset publik. Tidak ada login sebagai pengusul, upload, pengajuan, konversi baru, perubahan DB/config, restart atau deployment production. Dua file tes karakterisasi dan laporan/bukti ditambahkan lokal; kode aplikasi tidak diubah. File insiden 11379 yang sebelumnya untracked tidak disentuh.

## Kasus 10112: bukti langsung VM

- ID 10112 adalah dokumen; tidak ditemukan tiket verifikasi dengan ID tersebut.
- Status `revisi`, `current_step=2`, workflow 10004. Tiket revisi terakhir 11415 pada versi 10391/v3, direspons 10 November. Versi aktif 10432/v4 belum mempunyai tiket pengajuan; `file_pdf_path=NULL`. Seluruh empat versi kasus ini belum mempunyai snapshot PDF. Ini data legacy, bukan bukti snapshot hilang oleh kode baru.
- Versi aktif berukuran 683.684 byte, terakhir diperbarui DB `2026-11-13 15:54:46.990` (timestamp DB mentah; jangan langsung disamakan dengan zona waktu log Nginx).
- Akun pengusul lolos policy update. Workflow terkonfigurasi, tahap pertama parallel, tahap revisi serial berbasis role `verifikator`. Temuan template nonaktif di bawah **bukan penyebab kasus ini**.
- Pemeriksaan sebagai **www-data**: sumber ada dan terbaca; `inspect()` menghasilkan `unknown=[]`, `has_qr=true`. Akun deployment tidak dapat menelusuri direktori mode 2700 milik www-data; hasil `is_file=false` dari akun deployment sengaja tidak dianggap file hilang.
- Cache marker aktif tersedia: 1.958.345 byte, mtime `2026-11-12T06:52:02+00:00`; SHA-256 `e19e2929410f23055cb6d7be1b6a66541ba608799efea451249bd1dd7dc5abff`. Hash cache dihitung dari sumber dan metadata sesuai algoritma processor/renderer saat ini. Cache tanpa marker belum ada.
- Parser pypdf di VM, mode strict: 101 halaman, tidak terenkripsi, ukuran halaman 595,303937 × 841,889764 pt, semua stream halaman dapat dibaca. Ini **bukan** bukti PDF.js merender semua halaman dengan benar.
- HEAD worker production: HTTP 200, `Content-Type: application/javascript`. Kesalahan MIME pada audit 11 November tidak lagi terlihat pada pemeriksaan ini.

Bukti log tersanitasi: [nginx-sanitized.json](revision-preview-audit-2026-11-14/nginx-sanitized.json). Jendela: file log aktif, `.1`, dan `.2.gz` (12–14 November).

| Waktu WIB, 13 November | Request kasus 10112 | Status | Body bytes tercatat |
|---|---|---:|---:|
| 10:43:36 | preview v4 | 401 | 30 |
| 22:48:30 | preview v4 | 200 | 112.456 |
| 22:49:49 | preview v4 | 200 | 137.032 |
| 22:50:29 | upload versi | 499 | 0 |
| 22:52:52 | preview v4 | 200 | 153.416 |
| 22:53:56 | preview v4 | 200 | 79.688 |
| 22:57:19 | preview v4 | 200 | 104.264 |

Pada 12 November, preview v4 tercatat mengirim 1.958.345 byte setelah pembaruan terakhir cache. Tidak ditemukan POST `/dokumen/10112/ajukan` dalam jendela log yang diperiksa. Respons upload 302 sendiri **tidak membuktikan** upload berhasil, karena redirect validasi juga menggunakan 302.

HTTP 200 dengan body jauh lebih kecil daripada cache adalah indikasi transfer terhenti; 499 pada upload menguatkan adanya request yang ditutup klien, tetapi tidak membuktikan semua preview ditutup oleh tindakan pengguna. Koneksi jaringan, navigasi/reload, abort JavaScript, dan perilaku browser belum dapat dibedakan tanpa rekaman Network/Console. Request preview malam itu menggunakan Chrome 157 menurut user-agent; tidak ada bukti bug khusus versi tersebut.

Pemeriksaan nginx.conf, vhost dan conf.d tidak menemukan konfigurasi kompresi PDF; gzip default aktif untuk text/html. Kompresi gzip PDF di memori VM menghasilkan sekitar 668–719 KB, bukan 79–153 KB. Tidak ditemukan error upstream pada error log dalam jendela yang diperiksa. Log Laravel terakhir termodifikasi 12 November 06:18; tidak ada diagnostic preview kasus malam 13 November di sana. Ketiadaan log bukan bukti tidak ada kegagalan klien.

## Temuan kode dan reproduksi

### 1. Pemulihan request preview tertahan tidak tersedia — prioritas tinggi

`resources/js/document-preview.js:28–74`: setiap load mengosongkan token, men-disable tombol, menyembunyikan retry; kemudian menunggu `fetch` dan **seluruh** `response.blob()` sebelum viewer mulai. Tidak ada batas waktu aplikasi, progress download, atau tombol batal/muat ulang selama request pending. PDF 101 halaman tetap harus diterima penuh sebelum halaman pertama terlihat; lazy rendering canvas tidak mengatasi waktu download ini.

Tes Chrome sintetis menahan request dan membuktikan tombol disabled + retry tersembunyi. Pengamatan tes dibatasi 1,5 detik; kesimpulan tidak adanya timeout berasal dari inspeksi kode, bukan menunggu tanpa batas. PDF HTTP-200 terpotong direproduksi terpisah: viewer gagal, tombol tetap disabled, retry muncul. Ini menjelaskan mekanisme gejala dan konsisten dengan log 10112, tetapi belum mengidentifikasi penyebab awal transfer terhenti.

Perbaikan yang disarankan: timeout download/render yang jelas, pemulihan manual ketika pending, pesan tahap download versus render, penanganan abort berdasarkan penyebab, dan diagnostic request-ID. Jangan mengaktifkan tombol tanpa bukti review sah.

### 2. PDF tampil tanpa receipt menghasilkan keadaan tidak jelas — prioritas sedang

`document-preview.js:64–74`: token header yang hilang menjadi string kosong. Setelah render berhasil, status dikosongkan dan retry disembunyikan meskipun tombol tetap disabled. Tes browser mengonfirmasi keadaan ini.

Backend memang sengaja tidak menerbitkan receipt jika variabel asing ditemukan (`DocumentController.php:482–485`), dengan warning terpisah. Itu validasi bisnis yang disengaja, bukan bug konversi. Kekurangannya: frontend tidak mempunyai status eksplisit “PDF tampil tetapi belum memenuhi syarat pengajuan”, terutama jika receipt hilang tanpa warning. Pada 10112, variabel asing tidak ditemukan saat audit; header respons sesi pengguna belum direkam.

### 3. Snapshot versi lama diabaikan selama revisi — prioritas sedang

`DocumentController.php:470`: pemakaian `file_pdf_path` bergantung pada status **dokumen**, bukan apakah versi yang diminta sudah memiliki snapshot. Ketika status revisi, permintaan versi lama ikut dikonversi ulang. Reproduksi: ajukan v1 → minta revisi → upload v2 → kosongkan cache sementara di storage uji → konverter gagal → preview v1 HTTP 503 walaupun snapshot v1 masih ada.

Dampak: riwayat yang semestinya stabil ikut bergantung pada konverter dan metadata dokumen terkini. Gunakan snapshot untuk versi historis yang sudah diajukan; pastikan tidak menerbitkan token pengajuan untuk versi lama. Kasus 10112 belum punya snapshot sehingga temuan ini bukan penjelasan langsung kasus tersebut.

### 4. Form dan backend memilih konteks workflow berbeda — prioritas sedang

`DocumentController.php:158–159` memakai resolver template aktif untuk form; `DocumentService.php:199–214` memakai template dokumen yang tersimpan dan tahap peminta revisi untuk submit. Form selalu menampilkan konfigurasi tahap pertama, meskipun revisi kembali ke tahap lebih tinggi.

Tes membuktikan: ajukan → revisi → nonaktifkan template yang sudah terikat di DB uji → halaman menyatakan alur belum dikonfigurasi dan tidak menampilkan tombol; POST dengan receipt tetap diterima backend. Resolver form dan submit perlu memakai satu konteks pengajuan efektif. Untuk 10112, workflow masih aktif dan tahap pertama tidak meminta pilihan manual; mismatch ini belum terbukti memblokirnya.

### 5. Receipt lama berlaku melintasi putaran revisi dan pointer snapshot tertimpa — prioritas sedang

`DocumentController.php:486–495, 555–572`: receipt terikat user, dokumen, versi, sumber, hash PDF dan masa berlaku; tidak terikat putaran revisi/keputusan pengembalian. Tes: preview → ajukan → minta revisi → kirim receipt awal tanpa upload/preview baru → pengajuan diterima, tetap satu versi, `file_pdf_path` berganti ke snapshot baru.

Ini bukan bypass otorisasi pengguna lain; hash dan pemilik tetap diperiksa. Namun bukti review tidak menunjukkan review sesudah pengembalian, dan referensi snapshot pada versi yang sudah direview dapat diganti. Kebijakan boleh/tidaknya submit tanpa perubahan harus diputuskan eksplisit; jika diizinkan, receipt putaran baru dan jejak snapshot per putaran tetap perlu dipertimbangkan. Belum ada bukti kejadian ini pada 10112.

### 6. Cleanup navigasi tidak memiliki pemulihan pageshow — prioritas sedang, kondisi runtime belum diverifikasi

`document-preview.js:131–135` membatalkan request, membuang viewer dan mencabut object URL saat pagehide. Tidak ada handler pageshow untuk restore. Tes dispatch event persisted pagehide/pageshow membuktikan request tidak dimulai kembali dan UI pending tetap terkunci. **Tes ini simulasi lifecycle, bukan bukti halaman production benar-benar masuk BFCache.** Konfirmasi dengan navigasi Back/Forward pada browser pengguna sebelum mengaitkannya dengan kejadian nyata.

### 7. Request serentak dilaporkan sebagai kegagalan konversi — kelemahan keandalan

`DocumentPdfService.php:29–31`: request kedua untuk cache yang sedang dibuat langsung mendapat 503. Frontend tidak melakukan backoff/polling dan hanya menawarkan retry setelah error. Ini perilaku yang terlihat dari kode; belum dibuktikan sebagai penyebab 10112, yang cache-nya sudah tersedia. Bedakan “masih diproses” dari “konversi gagal” dan lakukan retry terbatas yang tidak menciptakan banyak proses Office.

## Alur yang sudah teruji dan batas pengujian

| Pengujian pada sesi audit | Hasil |
|---|---|
| UploadPreview + WorkflowRegression + EndToEndWorkflow + DocumentPdfService | 51 tes, 486 assertion lulus |
| Tes karakterisasi audit baru | 3 tes, 43 assertion lulus; kelulusan mengonfirmasi celah, bukan perbaikan |
| Office nyata: layout/marker, HTTP QR dua konfigurasi, DOC round trip | 4 tes, 45 assertion lulus |
| Browser suite yang sudah ada | Lulus: preview, marker, replacement guard, error/retry, scroll 92 halaman, canvas terbatas, responsif |
| Browser audit baru | Mengonfirmasi token hilang, PDF terpotong, pending tanpa recovery, simulasi lifecycle |
| Parse cache 10112 di VM | 101 halaman dan seluruh stream terbaca |

Tes feature memakai SQLite in-memory dan storage sintetis, bukan SQL Server production. Sebagian tes feature menggunakan PDF tiruan; tes Office terpisah memakai LibreOffice nyata. Percobaan Office pertama di sandbox gagal SIGABRT; eksekusi di luar sandbox lulus. Build tidak diulang karena kode/aset aplikasi tidak berubah; browser memeriksa build yang tersedia. Tidak dilakukan uji beban atau submit production. Bentuk layout Word tidak disertifikasi oleh audit ini.

Tes karakterisasi disimpan di `tests/Feature/RevisionPreviewAuditTest.php` dan `tests/browser/revision-preview-audit.cjs`. Setelah perbaikan, assertion karakterisasi perlu diubah menjadi kontrak perilaku yang diinginkan, bukan dipertahankan sebagai pembenaran bug.

Reproduksi lokal:

```sh
php vendor/bin/phpunit --do-not-cache-result tests/Feature/RevisionPreviewAuditTest.php
RUN_OFFICE_TESTS=1 php vendor/bin/phpunit --do-not-cache-result tests/Integration/DocumentOfficeRenderingTest.php --filter 'test_real_pdf_preserves_custom_landscape_page_and_marker_text_positions|test_http_overlay_renders_and_qr_scans_when_subprocess_path_is_missing|test_real_legacy_doc_round_trip_is_accepted_and_renders'
PLAYWRIGHT_MODULE=/path/to/playwright-core node tests/browser/document-preview.cjs
PLAYWRIGHT_MODULE=/path/to/playwright-core node tests/browser/revision-preview-audit.cjs
```

Jalankan tes storage/Office berurutan. Browser memerlukan Chrome, build tersedia, dan fixture PDF yang dihasilkan tes Office. Semua request suite browser diintersep; MIME production diperiksa terpisah lewat HEAD.

## Langkah berikut yang menentukan akar masalah kasus 10112

1. Rekam satu kejadian preview dari browser pengusul: status Network, Content-Length/bytes diterima, alasan cancelled/failed, console PDF.js, serta urutan upload/reload/toggle. Jangan mengekspor cookie/token autentikasi.
2. Tambahkan observabilitas preview yang tidak mencatat isi dokumen: request ID, document/version ID, cache hit, durasi konversi dan jumlah byte, status/penyebab abort di klien. Log Nginx saat ini tidak mencatat durasi atau sebab pembatalan sehingga akar transfer tidak dapat dipastikan.
3. Dahulukan recovery frontend tanpa melemahkan gate pengajuan; lalu satukan konteks workflow form/backend dan jaga snapshot historis. Uji lambat/terputus, multi-tab, upload berulang, navigasi kembali, sesi habis, serta revisi tahap 1 dan tahap lebih tinggi.

Akses VM awal memakai password ditolak automatic approval review; pengguna kemudian secara eksplisit menyetujui penggunaan kredensial `.env` untuk audit read-only. Ekspor PDF penuh ke laptop juga ditolak karena di luar izin metadata/log; tidak dijalankan, dan diganti parser di VM yang hanya mengeluarkan metadata. Karena itu render PDF asli 10112 di Chrome lokal tetap **belum terverifikasi**. Tidak perlu mengekspor PDF untuk melanjutkan pemeriksaan Network pada browser pengguna.

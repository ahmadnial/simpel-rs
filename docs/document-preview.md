# Upload Word dan preview PDF

Alur pengajuan menggunakan halaman yang sudah ada: unggah Word → periksa PDF → unggah ulang bila perlu → ajukan. Upload pertama menyimpan draft, belum mengirim ke verifikator. Tidak ada migrasi database, antrean baru, atau perubahan alur persetujuan.

## Perilaku

- Hanya `.doc` dan `.docx`, maksimum 10 MB; ekstensi tidak peka huruf besar/kecil. Validasi memeriksa isi berkas, bukan hanya MIME dari browser. Password/enkripsi, macro, objek tertanam, struktur rusak, dan batas ukuran internal mendapat pesan khusus. Dukungan tidak berarti semua berkas berekstensi Word pasti dapat diproses.
- Upload ulang sebelum pengajuan pertama mengganti berkas pada versi yang sama (v1 untuk draft baru). Setelah versi diajukan dan dikembalikan untuk revisi, upload perbaikan pertama membuat versi berikutnya; upload ulang berikutnya dalam siklus revisi itu tetap mengganti versi tersebut sampai diajukan kembali. Berkas dan PDF versi yang sudah diperiksa dipertahankan. Riwayat versi lama tidak dinomori ulang atau dihapus oleh perubahan ini.
- DOCX asli disimpan tanpa penyuntingan. DOC dinormalisasi menjadi DOCX melalui LibreOffice; bytes DOC asli disimpan sebagai berkas pendamping `.original.doc`.
- Penggantian variabel dilakukan pada salinan XML dokumen, termasuk token yang terpisah dalam beberapa text run. Properti halaman, paragraf, tabel, dan font dipertahankan. Variabel yang tidak dikenal ditampilkan sebagai peringatan dan perlu diperbaiki sebelum pengajuan.
- Preview otomatis dimuat saat halaman dibuka. Halaman PDF disusun berurutan dalam area scroll dengan indikator halaman. Viewer merender halaman di sekitar area pandang secara bergantian, lalu melepas canvas yang jauh agar dokumen panjang tidak menumpuk canvas di memori. Tombol coba lagi hanya muncul jika terjadi kegagalan.
- QR draft berwarna merah, termasuk ketika penanda variabel dinonaktifkan. Hasil pindai berupa teks `DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.` QR final tetap hitam dan mengarah ke halaman verifikasi resmi. QR draft menggunakan ukuran yang sama dengan QR final (65 pt) di lokasi `${qr_code}` atau `${barcode_tte}`. Isinya bukan tautan pengesahan. Tanpa variabel QR, preview memberi peringatan dan tidak menyisipkan QR di posisi sembarang.
- Variabel merah dapat dinonaktifkan melalui checkbox preview. Nilai yang belum tersedia, misalnya tanggal tanda tangan, tetap berupa token; tidak diisi dengan tanggal pengesahan palsu. Tanggal yang diketik manual tidak diubah.
- Kotak tanggal digambar sebagai lapisan browser pada koordinat anotasi PDF. Cara ini menghindari border Word yang dapat menggeser teks. Kotak hanya ada di viewer aplikasi; PDF yang dibuka terpisah tetap memiliki teks variabel merah pada mode penanda. Anotasi memakai domain `.invalid` dan viewer tidak membuka tautannya. PDF final tidak memiliki penanda/anotasi draft ini.
- Server memvalidasi bukti preview yang terikat pada pengguna, dokumen, versi, lokasi berkas sumber, hash sumber, hash PDF, dan masa berlaku satu hari sebelum pengajuan. Upload ulang membatalkannya. Ini membuktikan versi preview yang dimuat, bukan membuktikan pengguna membaca seluruh halaman.
- PDF yang diperiksa disimpan pada versi ketika diajukan. Preview berikutnya memakai salinan tersebut. Penandatanganan tetap menghasilkan PDF resmi dengan data final; dokumen resmi yang sudah tersimpan tidak dikonversi ulang untuk ditampilkan.
- OnlyOffice disembunyikan secara default sampai server disiapkan; pengguna menggunakan upload ulang.

## Konversi dan operasi

Gunakan satu renderer melalui `DOCUMENT_RENDERER=libreoffice` atau `gotenberg`. Tidak ada fallback ke HTML/DomPDF, karena hasilnya dapat mengubah layout tanpa diketahui pengguna. Kegagalan layanan ditampilkan sebagai kegagalan konversi, bukan otomatis menyalahkan berkas.

Untuk LibreOffice lokal, atur `LIBREOFFICE_PATH` bila executable tidak berada pada lokasi standar. Untuk Gotenberg, atur `GOTENBERG_URL`. **Import DOC tetap membutuhkan LibreOffice lokal**, termasuk ketika PDF memakai Gotenberg. Atur batas PHP/web server agar upload 10 MB dapat diterima. Konversi dibatasi 60 detik; hindari timeout proxy yang lebih pendek.

Pasang font dokumen yang diperlukan pada mesin/container renderer. Pertahankan versi renderer dan font antar-instance. Naikkan `DOCUMENT_RENDER_REVISION` setelah perubahan renderer/font agar cache PDF diperbarui. Cache menggunakan hash konten, file sementara, dan lock agar pembacaan tidak menerima hasil parsial. Konversi DOC berlangsung sebelum transaksi database dimulai.

Jalankan scheduler Laravel yang sudah tersedia. Perintah `php artisan documents:prune-previews` membersihkan cache preview berumur lebih dari tujuh hari, dijadwalkan setiap hari pukul 03:30. PDF yang diajukan dan PDF resmi berada di direktori terpisah dan tidak dihapus perintah ini.

Build aset melalui `npm ci` dan `npm run build`, lalu perbarui config cache melalui prosedur deployment aplikasi. Jangan mengaktifkan `ONLYOFFICE_ENABLED=true` sebelum layanan siap. Perubahan ini tidak menjalankan deployment, migrasi, atau perubahan konfigurasi production.

## Pengujian dan batas bukti

PHPUnit memakai SQLite in-memory dan mengabaikan config cache aplikasi melalui `phpunit.xml`, agar pengujian tidak memakai koneksi database lokal/production.

```sh
php vendor/bin/phpunit --do-not-cache-result tests/Unit/WordDocumentNormalizerTest.php tests/Unit/DocxTemplateProcessorTest.php tests/Unit/DocumentPdfServiceTest.php tests/Feature/DocumentUploadPreviewTest.php
npm run build
RUN_OFFICE_TESTS=1 php vendor/bin/phpunit --do-not-cache-result tests/Integration/DocumentOfficeRenderingTest.php
```

Tes Office memerlukan LibreOffice dan `pdftotext` (Poppler). Jalankan setelah suite PHP selesai, bukan bersamaan: tes menggunakan fake storage yang sama. Tes ini membandingkan dimensi halaman custom landscape dan koordinat teks antara mode bersih dan mode penanda, serta menguji round trip DOC → DOCX → PDF. Fixture bersifat sintetis.

Tes browser menggunakan `tests/browser/document-preview.cjs`, Chrome, dan Playwright Core yang dipasang terpisah. Setelah tes Office dan build, jalankan dengan `PLAYWRIGHT_MODULE` menunjuk modul Playwright Core dan `CHROME_PATH` menunjuk executable Chrome. Bila direktori temporary PHP dan Node berbeda, salin dua fixture `simpel-*-preview.pdf` ke direktori yang sama dan atur `PREVIEW_FIXTURE_DIR`. Seluruh request browser diintersep; tes ini memeriksa kontrak viewer dan aset build, bukan sesi aplikasi production.

Hasil pengujian awal refactor terkait fitur dan alur pendukung: **44 tes, 293 assertion lulus**. Integrasi LibreOffice: **2 tes, 5 assertion lulus**. Build Vite dan tes Chrome untuk halaman PDF, kotak tanggal, toggle penanda, pembatalan preview lama, upload pengganti, kegagalan konversi, serta retry juga lulus. Pengujian viewer scroll mencakup fixture sintetis 92 halaman, perpindahan awal/tengah/akhir, pelepasan canvas jauh, dan lebar layar 320/375/414/768 px.

Pengujian penuh setelah penyesuaian versi draft: **151 tes, 990 assertion, 18 error dan 6 failure**. Pembanding kode awal: **124 tes, 24 tes gagal**. Himpunan nama tes gagal sama persis, tanpa tambahan kegagalan. Tes khusus upload/pengajuan dan penyimpanan editor setelah penyesuaian versi: **19 tes, 197 assertion lulus**. Kegagalan lama mencakup fixture sumber dokumen yang tidak tersedia dan unique constraint pada putaran verifikasi SQLite; tidak diperbaiki di luar lingkup fitur ini.

Hasil pengujian tidak menjamin kompatibilitas seluruh versi/fitur Word atau bebas bug. Identik dengan Word asli tetap bergantung pada font, kemampuan renderer, dan perubahan panjang nilai variabel. Sebelum rollout, periksa dokumen nyata dari Microsoft Word dan ekspor DOCX Google Docs yang digunakan organisasi, terutama tabel, header/footer, nomor halaman, section, ukuran kertas khusus, serta font nonstandar.

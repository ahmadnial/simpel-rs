# Perbaikan backend workflow — 11 November 2026

## Status

Implementasi selesai di workspace dan lulus QC lokal. Tidak ada perubahan file
di `resources` atau `public`: view, CSS, JavaScript, dan aset UI tetap sama.
Production belum dideploy; konfigurasi live dan data bisnis production tidak diubah.

## Perbaikan berdasarkan urutan audit

1. **Tombol Ajukan / MIME worker:** `deploy/nginx/build-assets.conf` memetakan
   `.mjs` build ke MIME JavaScript dan tetap mengembalikan 404 untuk aset hilang.
   Snippet sudah diuji dengan Nginx terisolasi; masih harus dipasang pada vhost
   HTTPS production melalui rollout yang disetujui. Guard receipt preview tetap ada.
2. **Akun tanpa unit:** create/store menolak dengan 422 dan pesan yang menjelaskan
   perlunya penetapan unit, sebelum resolusi workflow atau penyimpanan dokumen.
   Tidak menetapkan unit default atau mengubah akun secara otomatis.
3. **F01, publikasi:** semua route publikasi memerlukan permission yang sudah ada,
   `dokumen.publikasi`. Service juga memeriksa izin, serta memuat/memeriksa status
   terbaru setelah mengunci dokumen untuk publish, unpublish, dan republish.
4. **F02/F05, kelayakan pemeriksa:** pool otomatis mengecualikan pengusul dan user
   nonaktif sebelum menghitung kecukupan kuorum. Guard keputusan juga menolak
   self-approval, termasuk tiket lama dan aksi pengusul sebagai delegasi.
5. **F03, identitas keputusan:** pengecualian antarle­vel memperhitungkan aktor
   aktual; aktor yang sudah menyetujui tidak bisa memberikan persetujuan kedua
   pada step/putaran yang sama melalui tiket lain. Kuorum menghitung aktor unik,
   dengan fallback pemilik tiket untuk keputusan lama yang actor-nya NULL.
   Scope antrean dan pemeriksaan detail mengikuti aturan tersebut.
6. **F04, sesi akun nonaktif:** middleware web menghentikan request, logout, dan
   membatalkan sesi akun nonaktif. Guard keputusan service juga memeriksa bahwa
   pemeriksa dan pemilik tiket aktif. Login pengguna aktif tetap memakai alur lama.
7. **F06, snapshot:** policy diperiksa ulang setelah dokumen dikunci. Setiap
   percobaan menggunakan path PDF unik; request yang kalah tidak menimpa PDF
   request yang sukses. Jika transaksi gagal, cleanup hanya menyasar berkas
   percobaan yang tidak direferensikan versi tersimpan. Jika pemeriksaan cleanup
   sendiri gagal, exception dilaporkan dan berkas tidak dihapus secara membabi buta.
8. **T01, SQLite:** migration korektif mengembalikan predicate indeks tiket aktif
   untuk SQLite saja. Pada SQL Server migration ini tidak menjalankan DDL;
   perilaku no-op tersebut mempunyai unit test. Tidak ada perubahan indeks
   SQL Server production yang diperlukan untuk perbaikan ini.

## QC dan bukti

| Pemeriksaan | Hasil |
| --- | --- |
| Suite penuh urutan normal, sebelum penambahan satu unit test guard migration | 180 tests, 1.419 assertions, lulus |
| Suite penuh urutan acak, seed `11112026`, termasuk unit test guard migration | **181 tests, 1.421 assertions, lulus** |
| LibreOffice nyata, dijalankan sendiri setelah suite PHP | **2 tests, 5 assertions, lulus** |
| Nginx terisolasi: konfigurasi, MIME `.mjs`, MIME `.js`, missing asset | **Lulus; 200/JavaScript, 200/JavaScript, 404** |
| PHP lint pada 18 file PHP baru/berubah | Lulus |
| Pint pada file baru dan `git diff --check` | Lulus |
| Diff pada `resources` dan `public` | Kosong |

Bukti ringkas mesin: `backend-fix-2026-11-11/qc-results.json`.

Suite lama yang sebelumnya 18 error dan 6 failure kini dapat mencapai assertion
tanpa error/failure. Fixture signing lama diperbaiki dengan membuat sumber DOCX
sintetis secara eksplisit, fake storage, dan renderer PDF pengujian. Fixture user
aktif dinyatakan secara eksplisit agar sesuai dengan default schema. Fixture
catatan panjang tidak lagi menuntut trailing whitespace yang memang dipangkas
middleware Laravel. Assertion keamanan dan validasi aplikasi tidak dihapus.
Konversi nyata tetap diuji terpisah dengan LibreOffice, bukan disimpulkan dari fake PDF.

`DocumentWorkflowRegressionTest` memuat 28 kasus setelah ekspansi dataset. Tes
mencakup publikasi tanpa izin, pool otomatis, delegasi, akun nonaktif, receipt
lama, replay tiket, pengembalian berulang, kuorum setelah revisi, snapshot gagal,
retry storage, interleaving dua pengajuan, dan alur hingga signing/publikasi berizin.
Workaround indeks di fixture audit sudah dihapus: round trip kini memakai hasil
migration aplikasi yang sebenarnya.

Perintah yang dapat diulang:

```sh
php vendor/bin/phpunit --do-not-cache-result
php vendor/bin/phpunit --do-not-cache-result --order-by=random --random-order-seed=11112026
RUN_OFFICE_TESTS=1 php vendor/bin/phpunit --do-not-cache-result tests/Integration/DocumentOfficeRenderingTest.php
```

Jalankan tes Office setelah tes lain selesai karena fake storage dipakai bersama.
`phpunit.xml` memaksa SQLite `:memory:` dan config cache testing terpisah; jangan
mengganti konfigurasi itu dengan koneksi production untuk menjalankan tes.

## Yang masih memerlukan lingkungan/data yang benar

- **Rollout Nginx belum dilakukan.** Gangguan tombol Ajukan di production belum
  boleh dinyatakan pulih sampai header worker live benar dan alur browser berhasil.
  Ikuti `deploy/nginx/README.md`, validasi `nginx -t` sebelum reload.
- **Concurrency SQL Server staging sudah lulus:** 10 skenario pada `simpel-dev`,
  memakai dua proses/koneksi nyata dengan blocking `LCK_M_U` teramati. Fixture
  dibersihkan dan fingerprint/jumlah baris 16 tabel kembali sama dengan baseline.
  Rincian dan batas cakupan: `2026-11-11-sqlserver-concurrency.md`.
- **QC browser melalui deployment staging masih diperlukan:** pengajuan awal →
  revisi → upload pengganti → preview → pengajuan ulang, termasuk back/forward
  dan kegagalan/retry. Tidak ada modifikasi JavaScript atau pelepasan guard tombol.
- **Draft production 10231 belum dipulihkan.** Backend recovery melalui upload
  pengganti sudah lulus tes tanpa bergantung pada preview sumber lama. Pemulihan
  dokumen nyata tetap harus memakai berkas sah dari pemilik/backup.
- **Penempatan unit akun harus diputuskan operator.** Kode tidak menebak unit
  untuk tujuh akun yang ditemukan tanpa unit pada audit.
- Kesehatan email OTP, provider signer, immutable storage, dan recovery operasional
  production tidak dibuktikan oleh keberhasilan adapter signing pengujian.

Sebelum deployment, pertahankan modifikasi `DocxTemplateProcessor.php` yang ditemukan
pada VM saat audit. Jangan menimpa perubahan VM tersebut tanpa review selisihnya.
Tidak diperlukan build frontend untuk perubahan kode ini karena asetnya tidak diubah.


QC lanjutan setelah akses staging diberikan: suite lokal kini **184 tes,
1.430 assertion lulus**, termasuk tiga tes guard harness staging. Tidak ada
perubahan kode backend tambahan yang diperlukan dari sepuluh skenario concurrency.

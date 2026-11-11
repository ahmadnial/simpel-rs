# Perbaikan layout PDF unggahan — 11 November 2026

**Pembaruan 12 November:** pembanding Word asli sudah tersedia dan membuktikan
hasil terbaru masih 109 halaman sedangkan Word 100. Kelulusan QC di bawah hanya
untuk stabilitas terhadap render LibreOffice. Lihat
[perbandingan fidelity Word](2026-11-12-word-fidelity-comparison.md) sebelum
menilai kesiapan perbaikan formatting untuk production.

Perbaikan lanjutan untuk kegagalan pada runtime HTTP, yang tidak tertangkap QC
CLI sebelumnya, dicatat di [QR HTTP runtime fix](2026-11-11-qr-http-runtime-fix.md).

## Perubahan dan batasnya

QR untuk PDF ditempatkan **setelah** konversi Office selesai. Intermediate DOCX
mempertahankan teks placeholder QR, ukuran font, tab, dan aliran paragraf. Teks
placeholder dibuat putih dan diberi hyperlink penanda privat; gambar QR tidak
dimasukkan ke aliran Word. PNG tetap berukuran 65 × 65 pt untuk draft dan final.

`DocumentQrOverlay` membuat satu stamp PDF menggunakan Dompdf yang sudah menjadi
dependency aplikasi. `scripts/pdf_qr_overlay.py` mengkloning dokumen PDF lengkap
dengan pypdf, mencari penanda, lalu menambahkan stamp tanpa memperbesar halaman.
Hanya anotasi lokasi QR milik proses ini yang dihapus. Tautan tanggal, tautan
dokumen lain, dan outline dipertahankan pada skenario yang diuji.

Posisi ditentukan dari pusat glyph placeholder yang diekspor Office. Kotak anotasi
hyperlink tidak selalu sama dengan glyph bounds: fixture font default menunjukkan
selisih vertikal sekitar 2,2 pt. Karena itu penempatan menggunakan glyph bounds,
bukan sekadar pusat kotak hyperlink.

Margin section, orientasi/ukuran kertas, paragraf kosong, tabel, dan sumber DOCX
tidak dinormalisasi. Endpoint DOCX untuk editor lama tetap memakai gambar inline
seperti sebelumnya; perbaikan ini berlaku untuk pipeline PDF preview, unduhan,
dan kandidat pengesahan. Tidak ada perubahan komponen UI.

Versi cache template menjadi `template-v9-pdf-qr`; cache renderer memakai prefix
`pdf-qr-v2`. Cache baru hanya dipublikasikan setelah overlay selesai. Kegagalan
membersihkan file temporary dan melepaskan lock, sehingga proses bisa dicoba lagi.
Publikasi DOCX menggunakan hard link atomik tanpa menimpa cache yang sudah ada,
agar marker tidak berubah saat worker lain mengonversi path yang sama. Test dua
proses juga mereproduksi race pembuatan direktori (`mkdir(): File exists`);
pembuatan direktori kedua cache kini menerima direktori yang dibuat worker lain
dan tetap memeriksa kegagalan sebenarnya.

Durasi lock mencakup timeout konversi dan overlay. PDF resmi dan PDF versi yang
sudah direview/disimpan tidak dirender ulang otomatis.

## Perlindungan penempatan

- Semua marker yang diharapkan harus ditemukan. Marker terbungkus ke beberapa
  rectangle, geometri ambigu, halaman terotasi/crop yang tidak didukung, atau QR
  keluar halaman tidak diteruskan diam-diam.
- QR tidak diperkecil otomatis. Pemeriksaan glyph mendeteksi jarak kurang dari
  2 pt dari teks lain dan dua QR yang saling menutup.
- Raster grayscale 144 DPI memeriksa area QR untuk mendeteksi gambar, garis tabel,
  dan drawing yang akan tertutup; hanya glyph placeholder sendiri yang diabaikan.
  Latar berwarna/bergambar juga dapat memicu peringatan.
- **Sesuai pilihan eksplisit pengguna, ruang tidak cukup/risiko overlap tetap
  diproses dengan peringatan**, termasuk token di antara teks. Konten sumber
  tidak digeser demi QR; konsekuensinya QR dapat menutup isi dokumen pada template
  tersebut. QR yang melewati tepi digeser masuk ke halaman tanpa diubah ukurannya
  dan diberi peringatan. Pengguna perlu memeriksa hasilnya.
- Peringatan ditulis ke metadata PDF `/SimpelQrWarnings` sebagai daftar halaman
  dalam JSON/base64 ASCII (maksimal 30 nomor). Endpoint membacanya dengan buffer
  terbatas dan meneruskannya lewat `X-Preview-Warnings`, yang sudah ditampilkan
  oleh UI. Metadata tetap ikut pada cache, salinan reviewed, dan kandidat final;
  ia termasuk bytes yang di-hash. Tidak ada sidecar atau kolom database baru.
- Lokasi ambigu, marker hilang/terbungkus, atau geometri tidak didukung tetap
  gagal; QR tidak boleh hilang atau diletakkan pada lokasi tebakan. Ambiguitas
  layout menghasilkan HTTP 422; kegagalan runtime/konversi menghasilkan HTTP 503.
  Tidak ada cache PDF parsial atau fallback yang menghilangkan QR.

Placeholder putih tetap merupakan teks di dalam PDF (termasuk hasil ekstraksi
dan kemungkinan pembacaan accessibility). Ia tidak dihapus/redact karena tinggi
dan lebar teks dipertahankan untuk layout. Penambahan QR bukan klaim validasi
PDF/UA atau kesetaraan seluruh fitur Word.

## QC yang telah dijalankan

- Suite PHPUnit reguler: **194 test, 1.500 assertion, lulus**. Database pengujian
  dipaksa SQLite memory oleh `phpunit.xml`.
- Tujuh skenario Office nyata diuji: marker on/off pada landscape; draft/final
  dengan URL UUID; overlap tetap diproses dengan peringatan; tabel berbingkai; header/footer berulang
  dua halaman (empat QR); fixture privat 109 halaman; round trip DOC lama.
  Hasil eksekusi terakhir: **7 test, 255 assertion, lulus**.
- Sembilan test Python lulus: metadata/outline/tautan/halaman; peringatan overlap teks;
  drawing tertutup; marker hilang/terbungkus; ukuran marker; batas halaman;
  dan rotasi yang tidak didukung.
- QR dibaca dari **raster PDF**, bukan hanya PNG sumber. Draft berisi pesan belum
  disahkan dan final berisi URL verifikasi; test final memakai UUID 36 karakter.
- Pipeline gagal lalu retry berhasil, lock dilepas, hanya hasil overlay lengkap
  di-cache, dan runtime QR hilang tidak menghilangkan QR secara diam-diam.
- Test HTTP memastikan peringatan tetap muncul pada cache hit dan PDF reviewed,
  tanpa menghalangi pengajuan setelah review. Ambiguitas QR tidak mengeluarkan
  token review. Test dua worker terpisah lulus tiga kali berturut-turut setelah
  perbaikan race cache; bytes yang dibaca kedua worker sama dan tidak berubah.

Fixture privat yang diberikan pengguna dibandingkan dengan render DOCX sumber
menggunakan LibreOffice yang sama, seluruh halaman:

| Kriteria | Hasil |
| --- | --- |
| Jumlah halaman | 109 → 109 |
| Ukuran semua halaman | Sama |
| Urutan kata pada setiap halaman | Sama |
| Selisih bounding box kata maksimum | 0,038 pt (sekitar 0,0134 mm) |
| Raster seluruh halaman, 72 DPI | 106 halaman identik; perbedaan di halaman 4, 5, 108 |
| Pixel berubah di luar area QR | 170 pixel total; jadi bukan klaim pixel-identical |
| SHA-256 sumber DOCX | Tetap sama |

Bukti mesin: [fix-qc.json](document-layout-2026-11-11/fix-qc.json).
Pergeseran besar 51,2–55,2 pt yang terjadi pada implementasi lama tidak ada lagi.
Sisa selisih kecil di atas dilaporkan apa adanya; penyebab mikro tidak diasumsikan
telah diisolasi. Halaman 4 dan 5 hasil baru juga ditinjau secara visual.

Artefak privat lokal untuk ditinjau:

- `storage/app/private/layout-qc/2026-11-11/laboratorium-source-render.pdf`
- `storage/app/private/layout-qc/2026-11-11/laboratorium-fixed-draft.pdf`

Simulasi final pada sumber privat yang sama (nomor/tanggal uji dan UUID dummy,
tanpa menyimpan tanda tangan/database dokumen) juga menghasilkan 109 halaman.
Seluruh kata dan koordinat sebelum/sesudah overlay identik; dua QR final berhasil
dibaca dari raster 150 DPI. Draft dan simulasi final contoh ini **tidak memicu
peringatan overlap**. Bukti: [final-simulation-qc.json](document-layout-2026-11-11/final-simulation-qc.json).
File `SIMULASI-final-with-qr.pdf` pada folder QC adalah simulasi, bukan pengesahan.

Acuannya adalah **render LibreOffice dari sumber**, belum PDF ekspor Microsoft
Word. Tidak ada janji kesetaraan 100% terhadap Word, semua font/engine, atau semua
template DOCX. Isian nomor/tanggal/nama yang lebih panjang tetap dapat mengubah
layout teks variabel; perbaikan ini menghilangkan reflow akibat sisipan gambar QR.

## Runtime dan reproduksi

Dependency tambahan: Python ≥ 3.10, `pypdf==7.0.1` (BSD-3-Clause), Poppler
`pdftotext` dan `pdftoppm`. LibreOffice/Gotenberg tetap menjadi satu renderer utama.
Default Python adalah `.venv-pdf/bin/python` di direktori aplikasi.

```sh
python3 -m venv .venv-pdf
.venv-pdf/bin/pip install -r scripts/requirements-pdf.txt
.venv-pdf/bin/python -m unittest discover -s tests/Integration -p 'test_pdf_*.py' -v
php artisan test --compact
RUN_OFFICE_TESTS=1 OFFICE_LAYOUT_FIXTURE='/path/to/private-source.docx' \
  vendor/bin/phpunit tests/Integration/DocumentOfficeRenderingTest.php --testdox
vendor/bin/phpunit tests/Integration/DocxTemplateCacheConcurrencyTest.php --testdox
```

Konfigurasi opsional: `DOCUMENT_PDF_PYTHON`, `DOCUMENT_PDFTOTEXT_PATH`,
`DOCUMENT_PDFTOPPM_PATH`. `.env.example` memuat contoh; `.venv-pdf` diabaikan Git.
Cache konfigurasi perlu dibangun ulang saat release agar key baru tersedia.
Test Office harus dijalankan berurutan dengan suite PHP lain karena fake storage
pengujian dipakai bersama.

Perubahan dan dependency saat ini hanya disiapkan/diuji di laptop. Manual CLI
yang memakai konfigurasi `.env` menggunakan cache lock pada `simpel-dev`
(`CACHE_STORE=database`); lock dilepas oleh service setelah konversi. Suite
otomatis menggunakan cache array dan SQLite memory. Tidak ada perubahan record
dokumen/workflow, schema, atau data production pada pekerjaan formatting ini.

Belum di-deploy atau diuji dengan runtime Linux/Gotenberg tujuan. Sebelum
deployment perlu menyiapkan dependency pada runtime tujuan
dan mengulang QC fixture dengan versi Office/font yang dipakai di sana. Staging
berada pada VM yang sama dengan production; perubahan service/font bersama tidak
termasuk pekerjaan lokal ini.

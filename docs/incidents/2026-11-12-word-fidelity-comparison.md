# Perbandingan hasil terbaru dengan Microsoft Word — 12 November 2026

Tindak lanjut implementasi dan hasil QC tersedia di
[perbaikan style tabel](2026-11-12-table-style-fix-qc.md). Patch mengurangi hasil
menjadi 101 halaman; kesetaraan Word dan keluhan halaman 11 belum lulus.

## Hasil

**Keluhan pengguna terkonfirmasi. PDF terbaru belum mempertahankan layout Word.**
DOCX yang sama diekspor langsung oleh Microsoft Word 16.101 menjadi **100 halaman**,
sedangkan `contoh_dokumen/hasil setelah perbaikan.pdf` mempunyai **109 halaman**.
Perbaikan QR sebelumnya menghilangkan tambahan reflow akibat gambar inline,
tetapi belum memperbaiki perbedaan layout pada tahap impor/render LibreOffice.

Sesi Codex Document Control tidak tersedia. Pembanding dibuat melalui Microsoft
Word lokal menggunakan salinan DOCX di temporary, kemudian salinan ditutup tanpa
menyimpan perubahan DOCX. Dokumen asli dan salinannya mempunyai SHA-256 yang sama
sebelum/sesudah ekspor. Tidak ada koneksi database atau perubahan kode aplikasi
pada analisis lanjutan ini.

## Identitas bukti

| Artefak | SHA-256 |
| --- | --- |
| DOCX sumber dan salinan QC | `1618647925247d653db4e2a3cb03a4b9f5df71402229ce2bcd38a0ef6ac5b8a3` |
| PDF terbaru dari pengguna | `70013252dca0927951d31c778d293089512682f89d219ec3f3ed5a6ef142686d` |
| PDF ekspor Microsoft Word | `117617e19581260f089f7d92dc3993e620506f1f4c2695293857c2bc1c9c9049` |

PDF terbaru identik byte-per-byte dengan
`storage/app/private/pdf_cache/office_05d4f42eda942edf1632ae7a61185896e87cfbfa7219e7e3f951665b6984128b.pdf`.
Metadata menyebut LibreOffice 26.2.5.2 dan waktu pembuatan 11 November pukul 23:19
WIB. Jadi yang dibandingkan benar hasil aplikasi terbaru yang diberikan pengguna.

## Halaman 11 yang dilaporkan pengguna

Posisi diukur dengan Poppler `pdftotext -bbox`, dari tepi atas halaman:

| Landmark | Word | SIMPEL terbaru | Selisih |
| --- | ---: | ---: | ---: |
| BAB II, halaman 11 | 85,588 pt | 121,609 pt | +36,021 pt / 12,707 mm |
| STANDAR KETENAGAAN, halaman 11 | 111,588 pt | 147,459 pt | +35,871 pt / 12,654 mm |
| Butir “Mempunyai integritas dan loyalitas yang tinggi” | Halaman 11 | Halaman 12 | Berpindah halaman |

Perubahan aliran halaman sudah tampak di halaman 9. Paragraf “Sampel atau
spesimen…” berjumlah **dua baris di Word** dan **tiga baris di LibreOffice/SIMPEL**.
Kata “diantaranya” masih muat pada baris pertama di Word, tetapi pindah ke baris
kedua di SIMPEL. Paragraf Cito setelahnya kemudian berlanjut ke halaman 10 pada
SIMPEL, sedangkan Word menyelesaikannya di halaman 9.

Landasan hukum di halaman 10 terdorong sekitar 40,7 pt. Tiga paragraf kosong pada
indeks 178–180 sebelum BAB II ikut mengalir; heading BAB II tidak memakai manual
page break atau `pageBreakBefore`. Karena itu tambahan baris pada halaman-halaman
sebelumnya memengaruhi posisi heading halaman 11. Angka indeks ini berbasis nol
pada urutan paragraf `word/document.xml`.

Ukuran font paragraf Sampel sama-sama **12 pt Times New Roman** pada kedua PDF.
Jadi kasus awal halaman 9 tidak dijelaskan sebagai perubahan ukuran font menjadi
lebih besar. Perbedaan penentuan titik putus baris masih perlu diselesaikan;
penyebab mikro glyph/shaping/kerning tidak dianggap telah terisolasi sepenuhnya.

Nilai margin section sumber tetap `1701` twip, sekitar 30 mm. MediaBox Word
595,25 × 842 pt, sedangkan LibreOffice 595,304 × 841,890 pt; selisih pembulatan
kertas kecil ini bukan pembenaran untuk mengubah margin dokumen secara massal.
Pergeseran heading 12,7 mm adalah hasil aliran konten, bukan bukti nilai margin
telah diubah menjadi 42,7 mm.

## Bug pewarisan style tabel yang terkonfirmasi

Pada tabel “Kualifikasi petugas laboratorium” halaman 12:

- Word memakai **10 pt** untuk “No.”, “Jenis Tenaga”, “Tenaga yang ada”, serta
  isi kualifikasi. PDF SIMPEL memakai **12 pt** untuk teks yang sama.
- Akibat yang terlihat: “No.” dan “Tenaga yang ada” membungkus; tinggi tabel
  bertambah. Baris “Petugas Teknis” yang masih muat di halaman 12 Word berpindah
  ke halaman berikutnya pada SIMPEL.
- Ukuran ini diperiksa dari operator/font size PDF memakai pypdf dan dikonfirmasi
  secara visual pada raster halaman 12; tidak hanya ditebak dari screenshot.

Rantai style pada `word/styles.xml`:

```text
Style29 (table)
  basedOn = TableNormal0
  tblPr/tblCellMar: left/right = 115 twip

TableNormal0 (table)
  rPr/sz = 20 half-point = 10 pt
  rPr/szCs = 20 half-point = 10 pt
  pPr/spacing: after = 0, line = 240, lineRule = auto

docDefaults
  rPr/sz = 24 half-point = 12 pt
  pPr/spacing: after = 160, line = 278, lineRule = auto
```

Run tabel tidak menentukan ukuran font secara langsung. Word menerapkan ukuran
warisan style tabel; renderer LibreOffice pada kasus ini tidak menghasilkan
ukuran yang sama. Nilai spasi paragraf dari style induk tabel juga material,
dibuktikan dengan kontrol terpisah di bawah. `DocxTemplateProcessor` sebelumnya
tidak mengubah `word/styles.xml`; file sumber bukan rusak karena upload.

## Eksperimen kausal, hanya pada salinan temporary

| Varian render LibreOffice | Perubahan salinan | Halaman | Font header tabel |
| --- | --- | ---: | ---: |
| Sumber tanpa pemrosesan aplikasi | Tidak ada | 109 | 12 pt |
| Font warisan dibuat eksplisit | 46 node `sz`/`szCs` pada style tabel | 106 | 10 pt |
| Spasi warisan dibuat eksplisit | 23 node `pPr/spacing` pada style tabel | 103 | 12 pt |
| Font dan spasi dibuat eksplisit | Gabungan 69 node | 101 | 10 pt |
| Kontrol kerning | `w:kern=0` → `1`, tanpa perbaikan style tabel | 109 | 12 pt |
| Gabungan style + kontrol kerning | Eksperimen kedua faktor | 101 | 10 pt |
| Referensi Microsoft Word | Sumber asli tanpa modifikasi | **100** | **10 pt** |

Materialisasi dilakukan pada properti style turunan yang belum memiliki nilai
sendiri, dengan menelusuri `basedOn`; margin, ukuran kertas, teks, dan paragraf
kosong tidak diubah. Ini membuktikan pewarisan font **dan** spasi berkontribusi
besar terhadap pertambahan halaman. Jumlah halaman tidak bersifat aditif linear;
kontrol terpisah diperlukan dan tidak ditafsirkan sebagai penjumlahan sederhana.

Varian kerning dapat memperbaiki letak heading awal, tetapi tidak mengembalikan
keseluruhan pagination dan belum membuktikan kesetaraan dengan Word. Tidak ada
varian tersebut yang diterapkan ke aplikasi; **101 halaman belum lulus target
Word 100 halaman**, dan jumlah halaman sama pun belum cukup tanpa perbandingan
isi/posisi per halaman.

Percobaan `SAL_FONTPATH` ke folder font Office tidak mengubah font yang benar-benar
terembed pada output kontrol. Karena intervensinya tidak terbukti mengubah font
renderer, hasil ini tidak dipakai untuk menyimpulkan font substitusi tidak
berpengaruh. Output Word memang mengandung antara lain Calibri/Cambria/Gungsuh,
sedangkan output saat ini mengandung Carlito/OpenSymbol/ArialUnicodeMS. Kontribusi
masing-masing substitusi belum diisolasi.

## Koreksi batas QC sebelumnya

PDF terbaru dibandingkan dengan **render LibreOffice langsung dari sumber**:
seluruh urutan kata per halaman sama, jumlah halaman sama-sama 109, selisih
bounding box maksimum hanya 0,04 pt. Dengan kata lain, kerusakan yang sekarang
dilaporkan sudah ada pada render dasar sebelum overlay QR.

QC sebelumnya berguna untuk mendeteksi tambahan reflow akibat QR serta masalah
runtime HTTP, tetapi menggunakan pembanding LibreOffice untuk layout sehingga
tidak menangkap perbedaan terhadap Word. **Kelulusan suite tersebut tidak boleh
ditafsirkan sebagai kelulusan fidelity Word.** Kini baseline Word asli tersedia
dan menunjukkan kegagalan yang nyata.

Perbandingan seluruh dokumen memakai urutan kata exact dan alignment global.
18.720 kata dapat dicocokkan; 15.083 di antaranya berada pada nomor halaman berbeda.
Perbedaan hitungan kata hasil ekstraksi (Word 19.293, SIMPEL 19.300) tidak otomatis
berarti teks hilang: pemisahan tanda baca, pembungkusan, dan urutan pembacaan tabel
memengaruhi ekstraksi. Pemeriksaan ini tidak mengklaim validasi semantik semua
objek hanya dari jumlah kata.

## Bahan perbaikan dan kriteria penerimaan

1. Perbaiki penerapan nilai warisan style tabel pada salinan konversi, dengan
   mempertahankan override milik run/paragraf/style turunan dan aturan style tabel.
   Jangan memaksa semua tabel menjadi 10 pt; nilai harus diturunkan dari sumber.
2. Selesaikan selisih pembungkusan/pagination yang tersisa, dimulai dari paragraf
   halaman 9. Jangan menggeser margin, menghapus paragraf kosong, atau mengubah
   jarak huruf global untuk menyamarkan satu halaman contoh.
3. Gunakan PDF Microsoft Word sebagai acceptance reference: 100 halaman; BAB II
   dan butir akhir tetap pada halaman 11; tabel halaman 12 memakai ukuran/spasi
   sumber dan tetap lengkap; validasi seluruh halaman, bukan hanya page count.
4. Pertahankan pengujian overlay QR, date annotation, draft/final, keterbacaan QR,
   source hash, dan runtime HTTP yang sudah ada. Fidelity Word adalah pemeriksaan
   tambahan yang wajib dipisahkan dari stabilitas overlay dan kesehatan runtime.

Baseline ini membuktikan kebutuhan perbaikan berikutnya; belum ada patch baru
aplikasi yang diklaim selesai atau siap production pada analisis ini.

## Artefak lokal dan bukti mesin

Folder privat `storage/app/private/layout-qc/2026-11-12/`:

- `microsoft-word-original.pdf` — ekspor Word, 100 halaman.
- `simpel-after-fix.pdf` — PDF terbaru pengguna, 109 halaman.
- `source-unchanged.docx` — salinan dengan hash sama.
- `comparison.html` — perbandingan visual halaman 11 dan 12 berdampingan.

Bukti terstruktur:
[word-vs-new.json](document-layout-2026-11-12/word-vs-new.json) dan
[controlled-experiments.json](document-layout-2026-11-12/controlled-experiments.json).

Referensi spesifikasi yang dipakai untuk mengevaluasi kontrol, bukan sebagai
pengganti hasil ukur: [Microsoft — Kern](https://learn.microsoft.com/en-us/dotnet/api/documentformat.openxml.wordprocessing.kern?view=openxml-3.0.1)
menjelaskan ambang ukuran font untuk kerning; [LibreOffice — Document Settings](https://api.libreoffice.org/docs/idl/ref/servicecom_1_1sun_1_1star_1_1document_1_1Settings.html)
mendokumentasikan `AddExternalLeading`. Pengaruh external leading tidak diuji
atau diklaim sebagai penyebab kasus ini.

# Analisis layout unggahan laboratorium — 11 November 2026

Implementasi dan hasil QC perbaikan berikutnya dicatat di
[document-layout-fix](2026-11-11-document-layout-fix.md). Bagian analisis di bawah
mempertahankan bukti diagnosis sebelum perbaikan.

## Kesimpulan

Pergeseran vertikal berhasil direproduksi dan kini diverifikasi memakai pasangan
file yang dikonfirmasi pengguna di `contoh_dokumen/`:
`Pedoman Pelayanan Laboratorium tahun 2026 ok.docx` dan
`f00e8586-ef8a-4218-b824-6de4912e2499.pdf`.
DOCX tersebut identik byte-per-byte dengan unggahan lokal ID 20059; PDF tersebut
identik byte-per-byte dengan cache SIMPEL yang dianalisis. Kecocokan tidak lagi
hanya didasarkan pada teks/screenshot.

**Penyebab langsung yang terkonfirmasi: placeholder QR teks diganti menjadi gambar
inline 65 pt; gambar menambah tinggi aliran teks dan menggeser konten sesudahnya.**
Layout sumber sendiri memakai paragraf kosong untuk menciptakan jarak. Kombinasi
keduanya membuat posisi lampiran tampak seperti margin atas berubah. Tidak ada
perubahan aplikasi atau data database selama analisis; konversi eksperimen hanya
memakai salinan temporary. Hash sumber sebelum/sesudah pemeriksaan tetap sama.

## Bukti sumber dan properti yang dipertahankan

Sumber: `storage/app/private/documents/20059/reA7Nf11LKCufsGevTz5tJJvHn6wd8VsgF2pt9Nx.docx`.
Salinan processor yang terhubung ke cache PDF:
`storage/app/private/temp_processed/aa346a8154707fde631b3c6ede6625302598867142cb16e5834e53f1d6e4598d.docx`.
Cache terkait: `office_c37349f755b6cd9515f7b22421a4389404a795c51b35624f0f1a06eae8853268.pdf`.
Keterkaitan cache diperiksa lewat rumus key renderer di `DocumentPdfService`.

- Dokumen sumber mempunyai 3.173 paragraf dan 7 section.
- Semua section yang diperiksa memakai margin `1701` twip, sekitar 30 mm.
- Perbandingan elemen main document `pPr`, `sectPr`, `tblPr`, `trPr`, dan `tcPr`
  antara sumber dan hasil processor: sama.
- File styles/settings/font table tidak termasuk part sumber yang berubah.
  Part yang berubah adalah document XML, relationships, footnotes, endnotes,
  dan footer1; sebagian penulisan ulang XML adalah bagian dari processor.
- Sebelum judul lampiran terdapat **17 paragraf kosong** (indeks 77–93, berbasis nol),
  tanpa drawing atau manual `w:br`. Baris ini memakai `line=240`, `lineRule=auto`.
- Sesudah placeholder QR pertama ada empat paragraf kosong, sebelum nama signer.
- Paragraf QR pertama memakai `line=360`, `lineRule=auto`: ini bukan kotak dengan
  tinggi ruang yang dijamin tetap untuk gambar baru.

Mempertahankan nilai properti tidak berarti mempertahankan hasil layout: engine
masih menghitung ulang tinggi baris ketika isi teks diganti menjadi gambar.

## Eksperimen kausal

Tiga salinan dikonversi dengan executable LibreOffice yang sama di laptop dan
profil konversi yang sama, tanpa mengubah dokumen pada storage aplikasi:

1. DOCX sumber tanpa pemrosesan aplikasi.
2. DOCX hasil processor, termasuk QR dan penanda variabel.
3. DOCX hasil processor yang hanya node gambar QR-nya dikembalikan menjadi
   `${qr_code}`. Kontrol final memakai PHP DOMDocument/ZipArchive agar namespace
   dan properties Word tidak diubah oleh serializer lain.

Posisi diukur dari atas halaman PDF menggunakan `pdftotext -bbox`, satuan pt:

| Landmark | Halaman | Sumber | Hasil processor | Kontrol tanpa gambar QR | Pergeseran processor |
| --- | ---: | ---: | ---: | ---: | ---: |
| Ditetapkan | 4 | 333,459 | 333,459 | 333,459 | 0 |
| TANTI pada nama signer | 4 | 492,159 | 543,359 | 492,159 | +51,2 pt / 18,1 mm |
| LAMPIRAN | 5 | 85,059 | 140,259 | 85,059 | +55,2 pt / 19,5 mm |
| BAB | 5 | 250,659 | 305,859 | 250,659 | +55,2 pt / 19,5 mm |

Koordinat X landmark tersebut tidak berubah. Ketiga PDF mempunyai 109 halaman
A4 dan ukuran halaman yang sama. Tabel ini adalah pengukuran landmark awal;
pemeriksaan otomatis seluruh 109 halaman dicatat pada bagian tambahan di bawah.
Tidak diklaim semua koordinat kata identik atau seluruh halaman ditinjau visual
secara manual.

Judul lampiran pada sumber mulai sekitar 30 mm dari atas halaman, sedangkan hasil
processor sekitar 49,5 mm. Nilai margin section tetap 30 mm. Ruang tambahan adalah
hasil flow/pagination dan paragraf kosong yang ikut bergeser, bukan perubahan
langsung nilai margin menjadi 49,5 mm.

Bukti koordinat: `document-layout-2026-11-11/coordinates.json`.
Salinan PDF eksperimen tersimpan di `/tmp/document-layout-audit/original/` pada
sesi laptop ini; bukan artefak production atau dokumen yang telah disahkan.

## Akar pada kode

`DocxTemplateProcessor::replaceToken()` mengganti run placeholder QR dengan hasil
`qrDrawing()`. Drawing menggunakan `wp:inline` dengan extent `825500` EMU pada
kedua sisi: 65 pt. Posisinya berada dalam aliran paragraf dan tidak mempunyai
reservasi tinggi tetap berdasarkan ruang dokumen sumber.

Urutan efek yang terbukti:

`placeholder teks → gambar inline lebih tinggi → tinggi baris berubah → nama
signer turun → paragraf kosong setelah NRP ikut mengalir → lampiran di halaman
berikutnya mulai lebih rendah`.

Mengubah warna QR menjadi hitam atau mematikan checkbox penanda tidak mengubah
sifat inline atau ukuran 65 pt. Menghilangkan baris kosong secara otomatis juga
bukan perbaikan yang menjaga sumber: itu akan mengubah layout yang memang ditulis
pengguna dan berisiko merusak dokumen lain.

## Viewer, font, dan variabel belum terisi

Viewer PDF.js merasterisasi halaman PDF memakai viewport dan scale; CSS canvas
menjaga rasio ukuran. Pergeseran ditemukan langsung dalam PDF hasil konversi,
sehingga kasus ini bukan semata-mata padding/zoom di UI. Kotak tanggal di browser
merupakan overlay, bukan border Word yang menambah tinggi paragraf.

Sumber mendeklarasikan Calibri, Cambria, Gungsuh, Symbol, dan Times New Roman.
PDF pembanding mengandung antara lain Times New Roman, Carlito, OpenSymbol,
ArialUnicodeMS, dan Symbol. Ini membuat kesetaraan font/engine tetap perlu diuji
terhadap baseline Microsoft Word. Tidak diklaim mapping fallback tertentu hanya
dari daftar font, dan font bukan penjelasan utama selisih QR pada eksperimen
terkontrol karena engine/font dijaga sama antara tiga varian.

`${nomor_surat}` dan `${tanggal_ttd}` pada screenshot masih berupa token draft.
Dalam kode, nomor/tanggal final memang belum selalu tersedia sebelum pengesahan.
Token merah sendiri tidak membuktikan kegagalan upload atau kerusakan berkas.

## Mengapa QC terdahulu belum menangkapnya

`DocumentOfficeRenderingTest` membandingkan PDF hasil processor dengan markers
false versus markers true. Kedua varian tetap menyisipkan QR 65 pt. Keduanya
bisa sama-sama bergeser terhadap sumber dan tetap lulus perbandingan tersebut.
Tes itu berguna untuk konsistensi penanda, tetapi bukan bukti bahwa layout sumber
Word dipertahankan. Fixture sintetis satu halaman juga tidak mewakili paragraf
kosong, section kontinu, dan efek pergantian halaman pada dokumen 109 halaman ini.

## Arah perbaikan yang tepat untuk ditinjau

1. Hindari memperbesar aliran teks ketika QR dimasukkan. Evaluasi overlay QR pada
   PDF yang dirender dari sumber, atau drawing berposisi tetap/tanpa text wrapping
   dengan anchor yang teruji. Jangan mengganti inline menjadi floating secara
   massal tanpa menguji tabel, header/footer, dan pergantian section.
2. Gunakan ruang penempatan yang eksplisit. QR harus muat di ruang yang tersedia,
   tanpa menabrak nama signer/teks lain. Jangan mengecilkan QR sembarang demi lolos
   layout karena ukuran dan keterbacaannya juga harus diverifikasi.
3. Pertahankan paragraf kosong, margins, tables, dan section sumber. Perbaikan
   template sumber dapat ditawarkan terpisah; bukan normalisasi otomatis pada
   semua unggahan.
4. Tambahkan baseline **sumber belum diproses → hasil processor → PDF draft → PDF
   final**, bukan hanya marker on/off. Ukur koordinat teks di luar area penggantian,
   jumlah/ukuran halaman, tabel, dan keberhasilan pembacaan QR. Gunakan salinan
   dokumen ini sebagai fixture privat; jangan commit isi institusional ke repo umum.
5. Perbaikan algorithm processor harus mengganti versi cache key supaya file lama
   yang sudah bergeser tidak dipakai kembali. Dokumen resmi yang sudah disimpan
   tidak boleh dirender ulang secara otomatis.

## Tentang target 100%

Terdapat tiga target berbeda: bytes sumber DOCX tetap identik; properti struktur
Word dipertahankan; serta hasil visual sama dengan Microsoft Word. Bukti untuk
salah satunya tidak otomatis membuktikan dua lainnya.

Penambahan QR dan pengisian variabel memang mengubah konten. Kriteria yang bisa
 diuji adalah elemen di luar area penggantian tidak berpindah, sementara nilai baru
muat pada area yang disediakan. Panjang nilai yang melampaui ruang harus ditangani
secara eksplisit, bukan menyebabkan reflow diam-diam.

Belum ada PDF referensi hasil ekspor Microsoft Word dari versi sumber yang sama.
PDF sumber pada eksperimen ini merupakan render LibreOffice, bukan baseline Word.
Karena itu belum dapat dijanjikan kesetaraan visual 100% dengan Word atau seluruh
varian DOCX. Diperlukan PDF ekspor Word sebagai acuan sebelum menilai fidelity
akhir, terutama font, pagination, section, dan penempatan gambar.


## Verifikasi pasangan file asli yang diberikan pengguna

SHA-256 DOCX: `1618647925247d653db4e2a3cb03a4b9f5df71402229ce2bcd38a0ef6ac5b8a3`.
SHA-256 PDF SIMPEL: `57bdacffb774c1d79060d93106d499f31bc6d0819d2fd20bff7f7af58352c0c9`.
Seluruh entry ZIP DOCX sumber sama dengan entry file upload; bukan hanya XML
teksnya. Untuk pasangan ini, proses upload tidak mengubah bytes DOCX asli.
PDF yang diberikan pengguna juga sama persis dengan cache renderer aplikasi.

Metadata PDF menyebut LibreOffice 26.2.5.2 (X86_64), 109 halaman A4. Reproduksi
menghasilkan teks dan seluruh word bounding boxes yang identik dengan PDF SIMPEL
pada seluruh 109 halaman, walaupun tidak diklaim bytes PDF reproduksi identik
karena metadata/ID internal dapat berbeda.

Perbandingan sumber yang dirender langsung versus PDF SIMPEL pada seluruh halaman:

| Halaman | Pergeseran vertikal dominan pada kata yang dapat dicocokkan di halaman sama |
| --- | --- |
| 4 | +51,2 pt |
| 5 | +55,2 pt |
| 6–7 | +41,4 pt |
| 8–9 | +20,7 pt |
| 108 | +51,2 pt, pada blok QR kedua |

Halaman 2 juga mempunyai pergeseran horizontal kecil 0,024 pt pada satu kata.
Perbedaan boundary kecil hingga 0,04 pt masih ditemukan pada beberapa kata dalam
kontrol tanpa gambar QR; penyebab mikro ini belum diisolasi dan tidak disamakan
dengan pergeseran besar akibat QR.

Teks yang seolah hilang dari bawah halaman 5 berpindah ke halaman 6; efek serupa
berlanjut hingga halaman 9. Urutan teks PDF sumber mempunyai 19.300 kata hasil
`pdftotext`, sedangkan PDF SIMPEL 19.298. Setelah dua placeholder `${qr_code}`
dikecualikan karena telah diganti gambar, **urutan seluruh kata identik**. Ini
bukti tidak ada kata teks lain yang hilang dalam ekstraksi pembanding ini, bukan
bukti bahwa semua gambar, objek, accessibility metadata, atau seluruh fitur Word
sudah tervalidasi sempurna.

Kontrol tanpa gambar QR mengembalikan pagination/posisi landmark serta aliran
teks; selisih bounding box yang tersisa maksimum 0,04 pt pada lima kata di empat
halaman. Dengan demikian penyebab dominan kedua blok dan efek berantai pada
halaman berikutnya adalah sisipan QR inline, bukan perubahan bytes sumber,
margin section, atau kehilangan teks saat upload.

Bukti mesin tambahan:
`document-layout-2026-11-11/supplied-comparison.json`.
PDF di `contoh_dokumen/` adalah hasil SIMPEL, bukan PDF ekspor Microsoft Word.
Jadi pasangan file ini sudah cukup untuk membuktikan pergeseran akibat pemrosesan
SIMPEL, tetapi tidak menutup kebutuhan baseline Word bila targetnya kesetaraan
visual dengan Microsoft Word, termasuk sebelum QR disisipkan.

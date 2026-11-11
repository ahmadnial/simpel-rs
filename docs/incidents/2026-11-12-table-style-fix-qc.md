# Perbaikan pewarisan style tabel dan hasil QC — 12 November 2026

**Status: patch backend diterapkan di workspace; fidelity Word belum lulus. Jangan deploy dengan klaim format identik.**

## Perubahan yang diterapkan

`DocxStyleCompatibility` menelusuri `basedOn` pada style tabel dan mengekspisitkan
ukuran font `sz`/`szCs` serta atribut spasi paragraf yang diwarisi. Nilai milik
style turunan tetap menang; override paragraf/run dan conditional region
dipertahankan. Properti tidak dipaksa menjadi ukuran tertentu. Parent yang hilang
tidak diisi dengan nilai tebakan; siklus ditolak dengan 422 tanpa cache parsial.

Normalisasi hanya dijalankan pada salinan perantara untuk PDF. DOCX sumber dan
jalur editor lama tidak diubah. Cache template dinaikkan menjadi
`template-v10-table-styles` sehingga cache sebelum patch tidak terpakai lagi.
Margin section, ukuran kertas, paragraf kosong, serta UI aplikasi tidak diubah.

## Hasil dokumen asli

| Pemeriksaan | Word sumber | PDF pengguna sebelum patch ini | Hasil aplikasi setelah patch |
| --- | ---: | ---: | ---: |
| Jumlah halaman | 100 | 109 | **101** |
| Ukuran font header tabel halaman 12 | 10 pt | 12 pt | **10 pt** |
| Posisi atas BAB II pada halaman 11 | 85,588 pt | 121,609 pt | **121,609 pt** |

Jadi bug tabel sudah diperbaiki, tetapi keluhan halaman 11 **belum selesai**.
Selisihnya masih 36,021 pt atau sekitar 12,707 mm. Setelah patch, alignment global
menemukan 19.087 kata yang cocok, dengan 2.435 berada pada nomor halaman berbeda.
Hitungan ini bukan bukti semantik seluruh objek atau ukuran kehilangan teks.

SHA-256 DOCX sumber tetap
`1618647925247d653db4e2a3cb03a4b9f5df71402229ce2bcd38a0ef6ac5b8a3`.
Detail hasil dan hash PDF ada di
[implemented-results.json](document-layout-2026-11-12/implemented-results.json).

## Verifikasi independen bahwa patch mempertahankan semantik Word

Kontrol dengan properti style yang sama dengan implementasi diekspor melalui
Microsoft Word 16.101. Hasil sebelum/sesudah sama-sama 100 halaman, urutan 19.293
kata per halaman identik, tidak ada kata berpindah halaman, dan selisih maksimum
bounding box **0,0 pt**. Ini memvalidasi perbaikan style untuk dokumen contoh;
bukan klaim semua dokumen akan identik pada LibreOffice.

Satu ekspor awal ditolak auto-review karena risiko salah memilih/menutup dokumen
Word. Alternatif yang disetujui menggunakan salinan read-only bernama UUID,
memastikan belum ada dokumen dengan nama itu, mencocokkan path lengkap, dan tidak
memiliki perintah menutup dokumen atau menyimpan DOCX. Salinan QC tersebut
dibiarkan terbuka; dokumen pengguna tidak ditutup.

## Pemeriksaan sisa pergeseran

Metadata font yang benar-benar terembed berbeda: referensi Word Times New Roman
7.02, LibreOffice laptop 5.01.4x, LibreOffice VM Linux 2.82. Mesin konversinya juga
berbeda: laptop LibreOffice 26.2.5.2, VM LibreOffice 24.2.7.2. Perbedaan nama versi
saja tidak dipakai sebagai pembuktian penyebab tunggal.

Kontrol dilakukan pada salinan dokumen dan konfigurasi font per proses:

- Font lengkap bawaan Office lokal (Times New Roman 7.00) benar-benar terpilih
  pada output Linux, tetapi hasil normalisasi menjadi 99 halaman. Jadi mengganti
  font secara sembarang tidak menyelesaikan target fidelity.
- Subset font dari PDF referensi benar-benar terpilih pada output Linux. Posisi
  BAB II pulih menjadi 85,559 pt, mendukung kontribusi metrik/set font pada kasus
  halaman 11. Namun seluruh dokumen tetap 101 halaman dan halaman berikutnya
  masih bergeser. Subset ini kehilangan data font lengkap, termasuk tabel
  shaping/kerning, sehingga hanya kontrol diagnostik dan **bukan solusi font
  production**. Pengaruh setiap keluarga font belum diisolasi satu per satu.
- Mengubah semua `kern=0` menjadi `1` tetap menghasilkan 100 halaman di Word,
  tetapi lima kata berpindah halaman dan koordinat berubah. Perubahan ini ditolak,
  meskipun beberapa heading LibreOffice terlihat lebih mendekati Word.
- Menambahkan pewarisan font family/kerning ke kontrol tidak memperbaiki sisa
  pergeseran pada laptop, sehingga tidak ditambahkan ke patch aplikasi.

Rujukan untuk memahami ambang kerning:
[Microsoft Open XML — Kern](https://learn.microsoft.com/en-us/dotnet/api/documentformat.openxml.wordprocessing.kern?view=openxml-3.0.1).
Implementasi import LibreOffice 24.2.7.2 mengubahnya menjadi boolean pada
[`DomainMapper.cxx`](https://github.com/LibreOffice/core/blob/libreoffice-24.2.7.2/writerfilter/source/dmapper/DomainMapper.cxx#L2087).
Hasil kontrol Word aktual tetap menjadi penentu penerimaan, bukan asumsi dari
spesifikasi saja.

## QC yang dijalankan

| Kelompok | Hasil |
| --- | --- |
| Suite aplikasi, SQLite memory + cache array | **200 lulus, 1.521 assertion** |
| Integrasi LibreOffice/Poppler/HTTP/Python | **9 lulus**, 263 assertion; satu gate Word dijalankan terpisah |
| Python overlay QR | **10 lulus** |
| Pembanding Word independen | **1 gagal**: aktual 101 halaman, referensi 100 |
| Pint dan `git diff --check` | Lulus |

Enam tes unit baru mencakup pewarisan bertingkat, penggabungan atribut spasi,
override font, conditional region, parent hilang, namespace Strict/prefix lain,
jalur editor lama, dan siklus. Tes Office mengukur ukuran aktual 10/12/14/16 pt
untuk inheritance serta override pada PDF yang dihasilkan.

Pengujian QR membandingkan render sumber yang **sudah dinormalisasi** dengan
hasil overlay; ini sengaja terpisah dari pembanding Word. Semua halaman, kata,
dan koordinat tetap dalam toleransi 0,05 pt terhadap kontrol Office tersebut.
QR draft/final dapat dipindai, tabel/header/footer tidak mengalir ulang akibat
overlay, kasus overlap tetap memberi peringatan sesuai keputusan pengguna, dan
regresi HTTP tanpa PATH lulus.

Percobaan Office pertama di sandbox berhenti dengan signal 6. Pengujian ulang
dengan akses proses/font macOS normal menghasilkan kelulusan di atas.

Perintah reproduksi (dokumen privat tidak dimasukkan sebagai fixture Git):

```sh
php artisan test
.venv-pdf/bin/python -m unittest discover -s tests/Integration -p 'test_pdf_qr_overlay.py' -v
RUN_OFFICE_TESTS=1 OFFICE_LAYOUT_FIXTURE="$PWD/contoh_dokumen/Pedoman Pelayanan Laboratorium tahun 2026 ok.docx" vendor/bin/phpunit tests/Integration/DocumentOfficeRenderingTest.php
RUN_OFFICE_TESTS=1 OFFICE_LAYOUT_FIXTURE="$PWD/contoh_dokumen/Pedoman Pelayanan Laboratorium tahun 2026 ok.docx" OFFICE_WORD_REFERENCE="$PWD/storage/app/private/layout-qc/2026-11-12/microsoft-word-original.pdf" vendor/bin/phpunit tests/Integration/DocumentOfficeRenderingTest.php --filter test_private_document_matches_microsoft_word_reference
```

Perintah terakhir saat ini harus gagal; jangan melonggarkan gate agar terlihat
lulus. Gate memeriksa pagination, urutan kata per halaman, ukuran halaman, lalu
koordinat teks. Itu belum menggantikan pemeriksaan visual seluruh gambar/objek.

## Lingkungan dan batas penyelesaian

Tidak ada koneksi database pada tugas patch formatting ini. Aplikasi production,
font global VM, dan konfigurasi `.env` tidak diubah. Semua aktivitas VM memakai
direktori sementara milik QC yang sudah dihapus dengan pemeriksaan path dan
kepemilikan. Tidak ada deployment. Pengguna mengonfirmasi server Windows/Word
belum tersedia; tidak ada ketergantungan baru ke layanan tersebut yang diterapkan.

Data dokumen sudah cukup. Sisa pekerjaan adalah kesetaraan font lengkap dan
perilaku layout renderer terhadap Word, termasuk halaman 9/11 dan drift bagian
akhir. Belum ada solusi menyeluruh yang terverifikasi dengan runtime yang ada.
Margin/kerning global tidak diubah untuk menyamarkan masalah. Persiapan production
tetap tertahan sampai gate Word dan pemeriksaan visual dokumen lengkap lulus.

Artefak privat untuk ditinjau:

- `storage/app/private/layout-qc/2026-11-12/backend-table-style-fix.pdf`
- `storage/app/private/layout-qc/2026-11-12/backend-comparison.html` (halaman 11/12)

Bukti terstruktur:
[patch-verification.json](document-layout-2026-11-12/patch-verification.json),
[implemented-word-comparison.json](document-layout-2026-11-12/implemented-word-comparison.json),
[word-style-control.json](document-layout-2026-11-12/word-style-control.json), dan
[word-kerning-control.json](document-layout-2026-11-12/word-kerning-control.json).

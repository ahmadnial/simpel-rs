# Ruang kosong lampiran dan QR — verifikasi 11379

Dokumen production 10178, versi 8 (version ID 10381),
`Panduan kredensial dan rekredensial.docx`. Pemeriksaan metadata memakai SELECT
terbatas. Seluruh rendering dan rasterisasi dilakukan pada salinan sementara
privat di VM; salinan tersebut dibersihkan. Sumber dan record production tidak
diubah. Setelah izin eksplisit pengguna, hanya DOCX sumber dan PDF versi ini disalin ke folder privat lokal yang diabaikan Git untuk pembanding Microsoft Word. Hash salinan diverifikasi.

## Fakta sumber yang tersimpan

- A4; margin atas/bawah/kanan 1701 twip, sekitar 3 cm; kiri 2268 twip, sekitar
  4 cm. Tidak ditemukan vertical alignment center pada section.
- Judul lampiran berada pada paragraf top-level indeks 51, didahului **24
  paragraf kosong berurutan** pada indeks 27–50, font eksplisit 12 pt dan spasi
  1,5 pada paragraf yang diperiksa. Tidak ada page break eksplisit pada heading.
- SHA-256 DOCX tetap
  `f85a7d2fc35c5af87057f7337e643abc5a05069f691b17ce351812241c7c3afa`.

## Perbandingan di VM

| Varian | Halaman | Posisi atas judul lampiran halaman 4 |
| --- | ---: | ---: |
| DOCX tersimpan → LibreOffice langsung | 22 | 189,058764 pt / 66,696 mm |
| DOCX perantara → LibreOffice, sebelum overlay QR | 22 | 189,058764 pt / 66,696 mm |
| PDF production | 22 | 189,058764 pt / 66,696 mm |

Urutan kata per halaman sebelum/sesudah overlay identik; selisih koordinat teks
**0,0 pt**. Render langsung sumber dibanding production juga memiliki urutan kata
identik, dengan selisih maksimum koordinat 0,092 pt. Jadi ruang kosong yang
dilaporkan sudah muncul sebelum overlay QR. Nilai margin section saja tidak
menjelaskan posisi awal teks: paragraf kosong ikut mengalir melewati halaman.

Pembanding Microsoft Word lokal menghasilkan 22 halaman dengan judul lampiran
halaman 4 pada Y 189,108 pt: selisih hanya 0,049236 pt (0,0174 mm) dari production.
Jadi ruang kosong juga muncul saat DOCX tersimpan dibuka di Word. Ini belum
membuktikan kesamaan dengan berkas asli sebelum upload/normalisasi. Jika
masukan awal berupa DOC, normalisasi DOC→DOCX juga harus dibedakan dari tahap
konversi PDF; asal format upload tidak disimpulkan hanya dari nama DOCX tersimpan.

## Risiko QR halaman 3 terkonfirmasi

Canvas QR 65 pt berada pada Y 238,151–303,151 pt. Tiga bounding box kata di
atasnya berakhir pada Y 242,793 pt, sehingga area canvas beririsan sekitar
4,642 pt / 1,638 mm. Rasterisasi PDF sebelum overlay pada 144 DPI menemukan
**300 piksel tinta dokumen di dalam canvas QR**, ditambah 243 piksel dalam area
jarak aman 2 pt. Peringatan bukan sekadar asumsi dari screenshot atau bounding
box. QR lain pada halaman 21 tidak memiliki irisan bounding box teks yang sama.

Overlay bisa mempertahankan koordinat teks sekaligus menutup tinta dengan
background stamp. Oleh karena itu pemeriksaan geometri teks saja tidak cukup
untuk menyatakan hasil visual aman. Sistem saat ini tetap memproses dengan
peringatan sesuai pilihan pengguna sebelumnya; hasil ini belum dianggap aman
untuk semua template.

## Batas dan langkah berikut

Jangan mengubah margin global atau menghapus semua paragraf kosong secara
otomatis. Penggantian Enter berulang dengan page break eksplisit merupakan
kandidat perbaikan template yang perlu diuji terhadap Word. Untuk QR, ruang
penempatan di antara judul penandatangan dan nama perlu diperiksa terpisah.

Pengguna mengizinkan salinan privat lokal khusus versi ini. Ekspor PDF dilakukan
melalui Microsoft Word lokal dengan preferensi konversi layanan daring nonaktif.
Pengguna tidak ingat apakah masukan awal DOC atau DOCX; asal format tetap belum
terverifikasi, bukan disimpulkan dari metadata aplikasi LibreOffice.

## Eksperimen template privat

Pada salinan uji saja, 24 paragraf kosong diganti dengan `pageBreakBefore` pada
judul lampiran. Satu paragraf kosong sesudah placeholder QR pertama dipindah ke
sebelumnya untuk menguji ruang QR. Isi seluruh paragraf berteks dan properti
section diperiksa tetap identik; SHA-256 sumber tetap sama. Word menghasilkan
22 halaman, dengan judul lampiran halaman 4 pada Y 85,588 pt (sekitar 30,19 mm),
naik 103,52 pt (36,52 mm) dibanding Word sumber. Ini membuktikan pengaruh paragraf
pemisah tanpa mengubah margin section.

Salinan ini merupakan kontrol diagnosis, bukan template pengganti yang sudah
lulus QC penuh. Penempatan QR pada kontrol belum dinyatakan lolos uji raster
atau pemindaian. Tidak ada perubahan kode aplikasi, cache production, record,
atau berkas sumber production. Belum ada perbaikan backend baru untuk kasus ini.

Bukti angka tersanitasi:
[evidence.json](verification-11379-layout-2026-11-12/evidence.json).

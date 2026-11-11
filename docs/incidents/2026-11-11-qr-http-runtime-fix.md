# QR preview 503 pada runtime HTTP — 11 November 2026

## Masalah dan akar penyebab

Pengguna melaporkan HTTP 503 pada
`GET /dokumen/20059/preview-pdf/20058?markers=1` di server lokal port 8001.
Log awal hanya mencatat `exit_code=1, reason=processing`.

Reproduksi dengan dokumen/versi yang sama dari `simpel-dev` berhasil melalui CLI.
SHA-256 sumber masih
`1618647925247d653db4e2a3cb03a4b9f5df71402229ce2bcd38a0ef6ac5b8a3`.
Karena itu suksesnya QC CLI sebelumnya belum membuktikan runtime HTTP sehat.

Probe terpisah pada PHP built-in HTTP server mereproduksi kegagalan menggunakan
salinan DOCX/PDF yang sama, tanpa autentikasi pengguna atau query database pada
probe. Diagnosis terkonfirmasi:

- `PHP_SAPI=cli-server`.
- Parent PHP mempunyai `PATH` yang memuat `/usr/local/bin`.
- Child Python yang dijalankan Symfony Process tidak mempunyai `PATH`;
  `shutil.which('pdftotext')` juga tidak menemukan executable.
- Pemanggilan `subprocess.run(['pdftotext', ...])` melempar
  `FileNotFoundError`, lalu ditangani sebagai error `processing`.
- Kedua executable terpasang di `/usr/local/bin`; masalahnya adalah resolusi
  executable pada child process HTTP, bukan file dokumen atau ketiadaan Poppler.

Environment CLI dan HTTP berbeda pada `Symfony Process::getDefaultEnv()` yang
terpasang. Memodifikasi vendor atau meneruskan seluruh environment request bukan
perbaikan yang diperlukan untuk masalah ini.

## Perbaikan

`DocumentQrOverlay` sekarang menyelesaikan konfigurasi `pdftotext_path` dan
`pdftoppm_path` menjadi executable absolut sebelum meneruskannya ke Python.
Path konfigurasi yang valid tetap digunakan; nama executable dicari menggunakan
Symfony ExecutableFinder dengan direktori standar Linux dan Homebrew sebagai
tambahan. Jika tool tidak tersedia, layanan menyebut komponen yang belum tersedia.
Tidak diperlukan perubahan `.env` agar konfigurasi basename yang ada bekerja.

Diagnosis error Python kini menyertakan nama jenis exception dan nomor baris
script pada log internal. Pesan exception mentah, path dokumen, isi dokumen,
dan URL verifikasi tidak dikeluarkan. Tidak ada perubahan UI, algorithm penempatan
QR, atau pengubahan margin pada perbaikan lanjutan ini.

## Verifikasi

- Probe **layanan overlay asli melalui HTTP**, menggunakan hasil konversi dokumen
  20059/versi 20058 dengan `markers=1`: **HTTP 200**, PDF valid, tanpa peringatan
  overlap. Probe terpisah ini tidak memakai sesi login pada port 8001.
- Sebelum/sesudah overlay HTTP: **109 → 109 halaman**, seluruh kata dan koordinat
  identik. Dua anotasi lokasi QR dibuang; ketiga anotasi tanggal tetap tersedia.
- Kedua QR hasil HTTP berhasil dipindai dari raster **150 DPI** dan berisi pesan
  draft yang benar. SHA-256 PDF probe:
  `5a8421c653029681eb7fd7b3b92fa4a4aef89f8bfdb5ed4373c5792d5268c32f`.
- Test regresi baru menjalankan server PHP HTTP sesungguhnya dengan `PATH`
  dihapus, memanggil layanan overlay menggunakan konfigurasi basename, dan
  memeriksa HTTP 200, geometri teks, serta QR yang terbaca. **1 test, 18 assertion,
  lulus** pada eksekusi yang dicatat. Database diisolasi ke SQLite memory.
- Test Python tambahan memeriksa diagnosis `FileNotFoundError` tanpa membocorkan
  path atau marker QR. Suite Python: **10 test lulus**.
- Suite PHP reguler setelah perbaikan: **194 test, 1.500 assertion, lulus**.
  Pemeriksaan Pint, syntax Python, dan `git diff --check` juga lulus.

```sh
RUN_OFFICE_TESTS=1 vendor/bin/phpunit \
  tests/Integration/DocumentOfficeRenderingTest.php --filter http_overlay --testdox
php artisan test --compact
.venv-pdf/bin/python -m unittest discover -s tests/Integration -p 'test_pdf_*.py' -v
```

Pembacaan record dokumen hanya pada `simpel-dev`; reproduksi memakai cache array,
dan probe HTTP memakai SQLite memory. Tidak ada update record dokumen, perubahan
data production, deployment, atau perubahan server aplikasi port 8001. PDF gagal
sebelumnya tidak masuk cache, sehingga tidak perlu membersihkan semua cache atau
mengunggah ulang sumber untuk mencoba perbaikan ini.

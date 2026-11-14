# Node dan dependency frontend production — 14 November 2026

Selesai diterapkan pada VM sekitar 08:21 UTC / 15:21 WIB. Pengguna mengizinkan masuk VM dan menangani hasil build/audit. Tidak dilakukan migration, perubahan data/schema database, login sebagai pengguna, submit dokumen, perubahan PHP aplikasi, restart layanan, penggantian Node sistem, atau perubahan .env/config cache.

## Temuan awal

VM dan lokal pada commit `38afc58`, tracked working tree bersih sebelum tindakan. Kode perbaikan preview dan manifest build yang sebelumnya diuji sudah cocok hash. VM menggunakan Node 18.19.1/npm 9.2.0, tidak memenuhi requirement Vite 7/PDF.js. Audit npm: 7 dependency terdampak (5 high, 2 critical), termasuk dependency transitif. Angka ini bukan bukti tujuh jalur eksploitasi pada aplikasi.

Audit lengkap mengidentifikasi axios, concurrently, follow-redirects, pdfjs-dist, shell-quote, source-map-js, dan vite. Advisory [PDF.js GHSA-hq66-cqwq-w95j](https://github.com/advisories/GHSA-hq66-cqwq-w95j) menyebut versi terdampak mulai 5.6.83 sebelum 6.2.108; kondisi eksploitasi mencakup scripting yang aktif. Viewer SIMPEL memakai API render canvas, bukan full viewer scripting; audit ini tidak mengklaim eksploitasi aplikasi terbukti. Paket tetap diperbarui ke versi yang tidak ditandai audit.

## Perubahan

- Node resmi **24.22.3** dipasang di `/home/nialapps/.local/share/simpel-rs/node-v24.22.3-linux-x64`, dengan SHA-256 dicocokkan terhadap SHASUMS256.txt dari nodejs.org. npm bawaan **11.19.1**. Node sistem tetap 18.19.1; shell global dan layanan lain tidak diubah. Node 24 tercantum sebagai LTS pada [jadwal resmi](https://nodejs.org/en/about/previous-releases).
- `package.json`/lockfile diperbarui dan diuji lokal dahulu: PDF.js **6.5.438**, Vite **7.3.8**, Axios **1.21.1**, concurrently **9.2.5**, beserta dependency transitif terkait. Tidak menggunakan `npm audit fix --force`; peralihan major PDF.js dilakukan eksplisit dan diverifikasi lewat tes viewer.
- `.node-version` mencatat 24.22.3; engines memakai Node 24; `.npmrc` mengaktifkan engine-strict untuk mencegah instalasi pada runtime tidak sesuai.
- `deploy/build-frontend.sh` memilih runtime khusus SIMPEL di Linux, menjalankan npm ci dari lockfile dengan install scripts dinonaktifkan, membangun ke staging, memeriksa manifest/aset, menyalin aset dahulu, lalu mengganti manifest. Aset hash lama tetap ada untuk tab yang masih terbuka. Seluruh tahapan berhasil memakai Node yang sesuai.
- Tidak memodifikasi dependency PHP atau file logika workflow pada VM.

Untuk build berikutnya, dari `/var/www/apps/simpel-rs`:

```sh
./deploy/build-frontend.sh
```

Jangan menganggap `node -v` di shell biasa telah berubah; script memilih runtime proyek secara eksplisit. `npm ci` langsung dengan Node sistem lama akan ditolak engine-strict. Jika perlu audit manual:

```sh
PATH="$HOME/.local/share/simpel-rs/node-v24.22.3-linux-x64/bin:$PATH" npm audit
```

## Bukti hasil

- `npm audit` di VM: **0 info, low, moderate, high, critical; total 0** pada waktu pemeriksaan. Bukan jaminan tidak ada vulnerability yang belum diketahui.
- Build Linux: 98 modul, berhasil tanpa EBADENGINE.
- Entry baru `app-DTuFk4AW.js`; viewer `pdf-preview-viewer-D0gJJM6e.js`; worker `pdf.worker.min-CIqJB0yW.mjs`.
- Hash package.json, package-lock.json dan manifest sama antara laptop dan VM. Manifest SHA-256: `05616f1b76ae3c4943b8a837abd21f4aa48a2b13a41527585b6e3927a7b29104`.
- Worker baru: HTTP 200, `Content-Type: application/javascript`, 1.271.915 byte.
- `/up`: HTTP 200 setelah deployment.
- Aset app/worker lama masih ada di VM.
- `.env` dan `bootstrap/cache/config.php` mempunyai hash sama sebelum/sesudah (nilai/isinya tidak diekspor).
- Ketiga suite Chrome lokal lulus setelah pembaruan PDF.js: viewer 92 halaman dan responsif, regresi preview, serta HTTP stream terputus/timeout/BFCache nyata.
- Smoke Chrome pada **origin production**: CSS, app, viewer chunk dan worker asli semuanya HTTP 200; PDF sintetis 92 halaman dirender sampai halaman terakhir; zero page errors. Hanya HTML/PDF sintetis diintersep lokal. Tidak dibuat endpoint production, tidak mengakses sesi/dokumen pengguna. Script tersimpan di `tests/browser/production-assets-smoke.cjs`.

Tes backend tidak diulang karena perubahan hanya dependency frontend dan proses build; hasil 206 tes backend sebelumnya tidak diklaim sebagai eksekusi baru. Uji Office tidak diulang karena renderer server tidak berubah. Browser pengguna/dokumen asli 10112 masih perlu reload untuk memakai aset baru; smoke sintetis tidak membuktikan jaringan pengguna tertentu sudah pulih.

## Cadangan dan rollback

Cadangan privat:
`/home/nialapps/.local/state/simpel-rs/frontend-backups/20261114T082107Z`

Berisi `before.tar.gz`, daftar file yang sebelumnya ada/tidak ada, node_modules lama, paket pembaruan, fingerprint file terlindungi, serta audit-after.json. Tidak berisi salinan .env atau DB. Script deployment mempunyai rollback otomatis untuk kegagalan build/audit; rollback tidak dijalankan karena seluruh tahapan berhasil.

Rollback manual bila diperlukan: pulihkan file terdaftar pada `before.tar.gz` termasuk manifest lama, lalu pindahkan kembali node_modules lama setelah mengamankan node_modules baru. File baru yang tidak ada sebelum rilis tercatat di `absent.list`. Jangan menghapus aset hash lama/baru yang masih mungkin dipakai tab browser. Runtime khusus Node dapat dibiarkan; tidak ada rollback database atau perubahan service yang diperlukan.

## Sinkronisasi Git

Perubahan `package.json`, `package-lock.json`, `.node-version`, `.npmrc`, dan `deploy/build-frontend.sh` sudah identik di laptop dan VM tetapi **belum di-commit/push**. Dokumentasi dan smoke test baru berada lokal. Jangan menimpa perubahan VM melalui reset/pull paksa. Masukkan perubahan lokal ke rilis Git, lalu rekonsiliasi perubahan lima file VM sebelum pull; simpan cadangan/patch dan bandingkan isi terlebih dahulu. Tidak dilakukan push atau pembuatan commit remote tanpa permintaan pengguna.

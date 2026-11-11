# Rilis perbaikan dokumen melalui Git

VM telah disiapkan pada 12 November 2026. Runtime QR, MIME worker, dan pool upload
tidak perlu dipasang ulang. Lihat
[hasil persiapan dan QC](../docs/incidents/2026-11-12-production-vm-prepared.md).
Perintah di bawah untuk operator; agen tidak menjalankan push/pull.

## Laptop

Pastikan seluruh perubahan aplikasi, skrip QR, tes, dan template server berada
dalam commit rilis. Jangan menambahkan `.env`, virtual environment, atau dokumen
asli `contoh_dokumen/`. Setelah commit diperiksa, push branch rilis yang disetujui
(workspace saat ini memakai `main`):

```sh
git push origin main
```

## VM

Jalankan sebagai akun deployment di `/var/www/apps/simpel-rs`. Siapkan jeda rilis
singkat agar request tidak membaca checkout yang belum lengkap. Ada hotfix lokal
pada satu file yang sudah tercakup pada rilis; simpan lewat Git sebelum pull:

```sh
cd /var/www/apps/simpel-rs
php artisan down
git stash push -m "pre-release document processor hotfix" -- app/Services/DocxTemplateProcessor.php
git pull --ff-only origin main
php artisan up
```

Jalankan setiap langkah berurutan dan berhenti jika salah satunya gagal. Bila
pull gagal, pulihkan hotfix dengan `git stash pop` sebelum membuka aplikasi
kembali. Bila pull sukses, **jangan pop stash hotfix lama**: implementasinya sudah
masuk kode rilis. Simpan stash/cadangan sampai verifikasi selesai. Tidak perlu
force reset atau menimpa `.env`.

Default runtime QR kompatibel dengan config cache lama VM. Bila SOP deployment
memerlukan rebuild cache, jalankan `php artisan config:cache` sebagai akun
deployment selama aplikasi masih dalam jeda rilis, setelah pull sukses dan
sebelum `php artisan up`. `.env` production hanya dapat dibaca akun deployment;
jangan meninggalkan aplikasi tanpa config cache. Tidak perlu `optimize:clear`
atau penghapusan cache database yang luas.

Tidak ada perubahan dependency PHP/frontend pada rilis ini. Satu migration baru
hanya memperbaiki SQLite dan merupakan no-op pada SQL Server. Jangan menjalankan
perintah reset, `migrate:fresh`, atau restore database untuk deployment ini.

## Setelah kode tersedia

```sh
curl --fail --head https://simpel.rsnurrohmah.my.id/up
curl --fail --head https://simpel.rsnurrohmah.my.id/build/assets/pdf.worker.min-FHbmGBN0.mjs
```

Worker harus memakai MIME JavaScript. Kemudian uji browser menggunakan dokumen
uji yang disetujui: upload → preview → pengajuan → revisi → upload ulang → preview
versi baru → ajukan ulang. Reload halaman agar browser mengambil respons terbaru.

Rilis ini memperbaiki workflow dan meningkatkan hasil konversi. Kesetaraan Word
100% belum tercapai: contoh masih 101 vs 100 halaman, termasuk pergeseran halaman
11. Jangan menyatakan uji kesetaraan Word lulus atau seluruh dokumen identik.

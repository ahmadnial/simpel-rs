# Rilis perbaikan dokumen melalui Git

Pembaruan frontend 14 November 2026 memakai Node 24 dan lockfile baru. Jalankan
`./deploy/build-frontend.sh` untuk build berikutnya; skrip memilih runtime SIMPEL
di VM, membangun ke staging, dan mempertahankan aset lama. Lihat
[hasil deployment dan rollback](../docs/incidents/2026-11-14-production-frontend-dependencies.md).
Source perubahan tersebut harus masuk Git sebelum pull rilis berikutnya.

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
php artisan config:cache
sudo chgrp www-data bootstrap/cache/config.php
chmod 0640 bootstrap/cache/config.php
php artisan up
```

Jalankan setiap langkah berurutan dan berhenti jika salah satunya gagal. Langkah
stash hanya diperlukan bila hotfix lokal tersebut masih ada; setelah deployment
sukses working tree VM bersih. Bila pull gagal, pulihkan hotfix dengan stash yang
baru dibuat sebelum membuka aplikasi kembali. Jika Git mengatakan
`No local changes to save`, jangan pop stash lama dari deployment sebelumnya.
Bila pull sukses, **jangan pop stash hotfix lama**: implementasinya sudah masuk
kode rilis. Simpan stash/cadangan sampai verifikasi selesai. Tidak perlu force
reset atau menimpa `.env`.

Default runtime QR kompatibel dengan config cache lama VM, tetapi cache
konfigurasi **wajib tersedia sebelum `php artisan up`**. Jalankan `config:cache`
sebagai akun deployment setelah seluruh langkah Git/Composer/clear-cache selesai.
Hook `post-autoload-dump` Laravel pada Composer dapat menghapus config cache.
Jangan menjalankan Composer atau `config:clear` setelah pembuatan cache tanpa
membangunnya kembali.

`.env` production tetap privat bagi akun deployment. File cache harus dapat
dibaca `www-data` tanpa membuka akses untuk pengguna lain (owner akun deployment,
group `www-data`, mode 0640). Tidak perlu `optimize:clear` atau penghapusan cache
database yang luas. Jangan menjalankan `key:generate` untuk menangani
`MissingAppKeyException`: pertahankan `APP_KEY` production yang sudah ada.

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

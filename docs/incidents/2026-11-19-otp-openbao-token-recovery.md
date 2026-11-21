# Pemulihan preflight OTP — 19 November 2026

Pemulihan credential diterapkan pada VM aplikasi pada sekitar 09:50 WIB.
Preflight aplikasi kembali lulus. Belum dilakukan pengiriman OTP melalui browser
atau penandatanganan dokumen pengguna sebagai bagian pemeriksaan ini.

## Penyebab dan bukti

- HEAD produksi `14aaded5c82d8f941754cbab971991b1b7aac7fa`; working tree tracked bersih.
- OpenBao initialized dan unsealed, MinIO health HTTP 200, worker/scheduler aktif.
- Token aplikasi mendapat HTTP 403 pada pembacaan transit key dan lookup-self.
  Lookup administratif terhadap token tersebut menghasilkan kategori `bad token`.
  Belum ada bukti yang membedakan expiry dari revocation.
- Token `.env` sama dengan cache; masalah bukan cache membawa token berbeda.
- Kategori log menunjukkan sealed pada 16 November, kemudian permission denied
  sejak 16 November malam hingga 19 November. Isi log dan credential tidak diekspor.

## Perubahan

- Backup privat `.env`, config cache dan crontab di VM:
  `/home/nialapps/.local/state/simpel-rs/otp-recovery/20261119T024906Z`.
  Backup mengandung secret, jangan salin ke repo atau laporan.
- Token pengganti orphan periodik 168 jam, hanya policy signer dan self-renewal;
  tanpa `root`/`default`. Akses administratif dengan token baru ditolak HTTP 403.
- Hanya `OPENBAO_TOKEN` di `.env` diganti, lalu config cache dibangun ulang.
  `.env` tetap 0600; cache tetap owner deployment, group www-data, mode 0640.
- Renewal per jam menit 17 ditambahkan ke crontab deployment dengan `flock` dan
  syslog tag `simpel-openbao-renewal`; empat entry cron lama dipertahankan.
- Worker menerima `queue:restart` untuk restart graceful. Tidak ada rotasi private
  key, unseal, perubahan schema, atau perubahan manual dokumen/evidence/outbox.

## Verifikasi

- Lookup dan renewal token berhasil; TTL setelah renewal 604800 detik.
- `DocumentService::assertSigningInfrastructureReady()` lulus.
- Canary acak ditandatangani OpenBao dan diverifikasi dengan Sodium;
  key tetap `openbao:simpel-rs-evidence:v1`. Canary bukan dokumen bisnis.
- MinIO Object Lock dan bucket versioning sama-sama `Enabled`.
- Outbox kosong; ceremony: 3 awaiting_user_signature, 8 failed; tidak direset.
- APP_KEY, konfigurasi database, mail dan queue dipastikan tetap sama.
- `/login` dan `/up` HTTP 200. Worker, scheduler dan cron aktif.
- Skrip renewal lulus lint dan lima skenario HTTP lokal dengan token sintetis:
  renew, lookup, 403, nonrenewable, TTL rendah. Semua memverifikasi token tidak
  tercetak di stdout/stderr. Pengujian tidak memakai credential produksi.

Batas: belum ada uji email OTP end-to-end atau penulisan bukti WORM baru.
Kesehatan Object Lock/versioning diuji lewat API baca, bukan canary write permanen.

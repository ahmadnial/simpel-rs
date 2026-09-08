# SIMPEL-RS — Setup Text Editor (OnlyOffice Docs) + JWT

Integrasi aplikasi sudah tersedia sebagai fitur **Text Editor**. Dokumen ini adalah runbook deployment; container, DNS, TLS, firewall, dan environment production tetap harus diterapkan operator server setelah backup dan change approval.

## Kontrak konfigurasi

Laravel dan OnlyOffice Document Server harus memakai secret JWT yang sama.

| Komponen | Lokal | Production |
|---|---|---|
| `APP_URL` | `http://host.docker.internal:8000` | `https://simpel.example.com` |
| `ONLYOFFICE_URL` | `http://localhost:8080` | `https://office.example.com` |
| `ONLYOFFICE_ALLOWED_HOSTS` | `localhost` | hostname yang muncul pada URL hasil callback Document Server |
| `ONLYOFFICE_JWT_SECRET` | secret lokal | secret production berbeda |
| Document Server port publik | `8080` (lokal) | hanya melalui HTTPS reverse proxy |

`APP_URL` wajib dapat dijangkau oleh container OnlyOffice karena dipakai untuk signed download dan callback. Callback dilindungi JWT serta signed URL sementara. `ONLYOFFICE_ALLOWED_HOSTS` adalah allowlist hostname URL hasil edit yang diunduh Laravel dari Document Server.

## Generate secret

Jangan commit secret ke Git atau menaruhnya di dokumen ini.

```bash
openssl rand -hex 32
```

Gunakan secret berbeda untuk lokal dan production.

## Setup lokal — Docker Desktop

1. Jalankan Laravel pada interface yang dapat diakses container:

   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```

2. Isi `.env` Laravel:

   ```env
   APP_URL=http://host.docker.internal:8000
   ONLYOFFICE_URL=http://localhost:8080
   ONLYOFFICE_JWT_SECRET=<SECRET_LOKAL>
   ONLYOFFICE_ALLOWED_HOSTS=localhost
   ```

3. Buat file lokal yang tidak di-commit, misalnya `onlyoffice.env`:

   ```env
   JWT_ENABLED=true
   JWT_SECRET=<SECRET_LOKAL>
   JWT_HEADER=Authorization
   JWT_IN_BODY=true
   ```

4. Jalankan Document Server:

   ```bash
   docker run -d \
     --name onlyoffice-documentserver \
     --restart always \
     --add-host host.docker.internal:host-gateway \
     -p 8080:80 \
     --env-file onlyoffice.env \
     onlyoffice/documentserver
   ```

5. Refresh konfigurasi Laravel:

   ```bash
   php artisan optimize:clear
   ```

6. Verifikasi:

   ```bash
   curl http://localhost:8080/healthcheck
   ```

   Buka aplikasi melalui `http://host.docker.internal:8000`, bukan `localhost:8000`, agar URL callback dapat dijangkau container.

### Catatan Linux lokal

Docker Linux memerlukan `--add-host host.docker.internal:host-gateway`. Jika aplikasi berjalan di container terpisah, gunakan nama service Docker pada `APP_URL` dan pastikan kedua container berada pada network yang sama.

## Setup production — VPS

### DNS dan TLS

Siapkan dua hostname:

- `simpel.example.com` → Laravel
- `office.example.com` → VPS yang sama

Pasang TLS pada keduanya. Jangan mengekspos port `8080` langsung ke internet.

### Environment Laravel

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://simpel.example.com

ONLYOFFICE_URL=https://office.example.com
ONLYOFFICE_JWT_SECRET=<SECRET_PRODUCTION>
ONLYOFFICE_ALLOWED_HOSTS=office.example.com
ONLYOFFICE_DOWNLOAD_URL_TTL_MINUTES=60
ONLYOFFICE_CALLBACK_URL_TTL_MINUTES=1440
ONLYOFFICE_CALLBACK_TIMEOUT_SECONDS=30
ONLYOFFICE_MAX_DOCUMENT_KILOBYTES=10240
```

### Container Document Server

Gunakan manifest [`deploy/onlyoffice/compose.yaml`](deploy/onlyoffice/compose.yaml). Tentukan image dengan tag immutable atau digest yang sudah diuji; jangan melakukan upgrade major/minor tanpa staging.

```bash
sudo install -d -m 750 /etc/simpel-rs
sudo cp deploy/onlyoffice/onlyoffice.env.example /etc/simpel-rs/onlyoffice.env
sudo chmod 600 /etc/simpel-rs/onlyoffice.env
```

Isi `/etc/simpel-rs/onlyoffice.env` dengan secret yang sama seperti `ONLYOFFICE_JWT_SECRET` Laravel:

```env
JWT_ENABLED=true
JWT_SECRET=<SECRET_PRODUCTION>
JWT_HEADER=Authorization
JWT_IN_BODY=true
```

Kemudian jalankan:

```bash
export ONLYOFFICE_IMAGE='onlyoffice/documentserver@sha256:<DIGEST_YANG_DISETUJUI>'
sudo --preserve-env=ONLYOFFICE_IMAGE docker compose -f deploy/onlyoffice/compose.yaml pull
sudo --preserve-env=ONLYOFFICE_IMAGE docker compose -f deploy/onlyoffice/compose.yaml up -d
```

### Nginx reverse proxy

Salin [`deploy/onlyoffice/nginx.conf.example`](deploy/onlyoffice/nginx.conf.example), ganti `office.example.com`, tambahkan konfigurasi sertifikat sesuai standar server, lalu aktifkan site.

Setelah DNS dan sertifikat aktif:

```bash
sudo nginx -t
sudo systemctl reload nginx
php artisan optimize:clear
php artisan config:cache
curl -fsS https://office.example.com/healthcheck
sudo docker exec simpel-rs-documentserver curl -fsS https://simpel.example.com/up
```

Perintah kedua wajib berhasil dari dalam container. Jika gagal, Text Editor tidak dapat mengambil signed download URL atau mengirim callback penyimpanan.

## Checklist sebelum eksekusi

- [ ] Backup database dan storage dokumen.
- [ ] Pastikan secret lokal dan production berbeda.
- [ ] Pastikan secret Laravel identik dengan `JWT_SECRET` container pada environment yang sama.
- [ ] Pastikan `APP_URL` dapat diakses dari network container OnlyOffice.
- [ ] Pastikan `office.example.com` resolve ke VPS dan TLS valid.
- [ ] Pastikan firewall hanya membuka 80/443; port 8080 tetap loopback.
- [ ] Uji membuka editor, menyimpan DOCX, dan menerima callback status 2/6.
- [ ] Pastikan penyimpanan menghasilkan versi dokumen baru dan catatan “Disunting melalui Text Editor”.
- [ ] Pastikan dokumen yang sudah diajukan tidak dapat menerima callback edit baru.
- [ ] Periksa `docker logs onlyoffice-documentserver` dan log Laravel bila callback gagal.

## Troubleshooting singkat

- `Invalid OnlyOffice JWT`: secret, header, atau waktu sistem tidak sama.
- Editor tidak memuat: `ONLYOFFICE_URL` tidak dapat dijangkau browser atau mixed-content HTTPS.
- Callback timeout: `APP_URL` tidak dapat dijangkau container.
- URL callback ditolak aplikasi: hostname pada `url` callback belum ada di `ONLYOFFICE_ALLOWED_HOSTS`.
- Container restart dan token tiba-tiba invalid: secret belum dipersistenkan melalui environment file.

Referensi resmi: [OnlyOffice Security/JWT](https://api.onlyoffice.com/docs/docs-api/get-started/how-it-works/security/) dan [instalasi Docker Document Server](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-docker.aspx).

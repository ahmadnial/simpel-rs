# SIMPEL-RS — Rencana Setup OnlyOffice Docs + JWT

Dokumen ini adalah runbook persiapan. **Belum ada container, service, DNS, firewall, atau environment production yang diubah.** Eksekusi dilakukan pada sesi terpisah setelah backup dan akses server dikonfirmasi.

## Kontrak konfigurasi

Laravel dan OnlyOffice Document Server harus memakai secret JWT yang sama.

| Komponen | Lokal | Production |
|---|---|---|
| `APP_URL` | `http://host.docker.internal:8000` | `https://simpel.example.com` |
| `ONLYOFFICE_URL` | `http://localhost:8080` | `https://office.example.com` |
| `ONLYOFFICE_ALLOWED_HOSTS` | `localhost` | `office.example.com` |
| `ONLYOFFICE_JWT_SECRET` | secret lokal | secret production berbeda |
| Document Server port publik | `8080` (lokal) | hanya melalui HTTPS reverse proxy |

`APP_URL` wajib dapat dijangkau oleh container OnlyOffice karena dipakai untuk signed download dan callback. `ONLYOFFICE_ALLOWED_HOSTS` adalah hostname tempat URL file callback OnlyOffice berasal.

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
```

### Container Document Server

Simpan `/etc/onlyoffice/onlyoffice.env` dengan permission terbatas:

```env
JWT_ENABLED=true
JWT_SECRET=<SECRET_PRODUCTION>
JWT_HEADER=Authorization
JWT_IN_BODY=true
```

```bash
sudo chmod 600 /etc/onlyoffice/onlyoffice.env
sudo docker run -d \
  --name onlyoffice-documentserver \
  --restart always \
  -p 127.0.0.1:8080:80 \
  --env-file /etc/onlyoffice/onlyoffice.env \
  onlyoffice/documentserver
```

### Nginx reverse proxy

```nginx
server {
    listen 443 ssl http2;
    server_name office.example.com;

    client_max_body_size 20m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 3600;
        proxy_send_timeout 3600;
    }
}
```

Setelah DNS dan sertifikat aktif:

```bash
sudo nginx -t
sudo systemctl reload nginx
php artisan optimize:clear
php artisan config:cache
sudo docker restart onlyoffice-documentserver
curl -fsS https://office.example.com/healthcheck
```

## Checklist sebelum eksekusi

- [ ] Backup database dan storage dokumen.
- [ ] Pastikan secret lokal dan production berbeda.
- [ ] Pastikan secret Laravel identik dengan `JWT_SECRET` container pada environment yang sama.
- [ ] Pastikan `APP_URL` dapat diakses dari network container OnlyOffice.
- [ ] Pastikan `office.example.com` resolve ke VPS dan TLS valid.
- [ ] Pastikan firewall hanya membuka 80/443; port 8080 tetap loopback.
- [ ] Uji membuka editor, menyimpan DOCX, dan menerima callback status 2/6.
- [ ] Periksa `docker logs onlyoffice-documentserver` dan log Laravel bila callback gagal.

## Troubleshooting singkat

- `Invalid OnlyOffice JWT`: secret, header, atau waktu sistem tidak sama.
- Editor tidak memuat: `ONLYOFFICE_URL` tidak dapat dijangkau browser atau mixed-content HTTPS.
- Callback timeout: `APP_URL` tidak dapat dijangkau container.
- URL callback ditolak aplikasi: hostname pada `url` callback belum ada di `ONLYOFFICE_ALLOWED_HOSTS`.
- Container restart dan token tiba-tiba invalid: secret belum dipersistenkan melalui environment file.

Referensi resmi: [OnlyOffice Security/JWT](https://api.onlyoffice.com/docs/docs-api/get-started/how-it-works/security/) dan [instalasi Docker Document Server](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-docker.aspx).

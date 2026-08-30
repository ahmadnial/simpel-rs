# Deployment and rollback handoff

## Deliverable aplikasi

Migration additive v2, OTP transaction challenge, signing ceremony/state machine, canonical manifest, institutional signer interface, deterministic bundle/offline verifier, upload-PDF verifier, audit chain/checkpoint, immutable-store interface/read-back/reconciliation, monitoring/redaction, dan administrative revocation flow telah tersedia. Data legacy tidak ditulis ulang; record tanpa evidence v2 tetap dilaporkan sebagai legacy/tidak tersedia.

Konfigurasi source hanya berisi placeholder variabel. Dua secret OTP harus berbeda, minimal 32 byte, dan diinjeksi ke `.env` production dengan permission ketat. Nilai dibaca ke config Laravel agar tetap bekerja setelah `config:cache`; jangan isi `.env.example` atau commit `bootstrap/cache/config.php`.

Deployment VM saat ini memakai OpenBao Transit Ed25519 dan MinIO Object Lock pada loopback. Aplikasi hanya memegang token OpenBao dengan izin read-key/sign serta credential MinIO tanpa izin delete. Root token, root object-store credential, dan unseal shares tidak digunakan aplikasi.

SQL Server runtime memakai principal `simpel_rs_app`, bukan `sa`, `sysadmin`, atau `db_owner`. Credential migration lama dipisahkan ke `/opt/simpel-tte/db-migration-admin.env`, owner `root:root`, mode `600`, dan tidak dibaca proses web/queue. Object-level `DENY UPDATE, DELETE` diterapkan pada enam tabel bukti append-only; `audit_chain_streams` hanya boleh mengubah head dan tidak boleh dihapus. Migration berikutnya harus dilakukan lewat prosedur DBA/migration credential, kemudian config cache dikembalikan ke credential runtime.

Runtime minimum dinyatakan eksplisit sebagai PHP 8.4 karena dependency permission v7 pada lockfile sejak baseline memang mensyaratkannya; Composer memakai platform 8.4 agar dependency baru tidak diam-diam menaikkan minimum tersebut.

## Gate produksi wajib — semuanya harus disahkan

- OpenBao/MinIO dan adapter aplikasi sudah aktif dengan least privilege dan retention compliance 3.650 hari. Yang masih memerlukan keputusan organisasi: dual control atas unseal shares, backup/recovery off-VM, rotasi key, dan kanal publik fingerprint.
- MinIO Object Lock/versioning/read-back sudah diuji. Domain administratif kedua dan restore drill off-VM masih wajib sebelum klaim disaster recovery.
- DBA menerapkan serta membuktikan [SQL Server append-only permissions](SQLSERVER_APPEND_ONLY_PERMISSIONS.md) dengan principal aplikasi non-owner.
- Rotasi seluruh credential yang pernah terekspos; forced reset dan pencabutan sesi akun terdampak.
- NTP/clock drift alert, SIEM routing, scheduler/queue supervision, on-call dan escalation owner.
- Maker-checker untuk perubahan/recovery email direktur, matriks kewenangan, SOP delegasi, privacy/retention/legal review, dan kanal incident response.
- Staging SQL Server menjalankan migration/rollback, deadlock/retry, dan concurrency load test dengan topology production.
- Security review dan penetration test independen menutup atau menerima formal seluruh temuan critical/high.

Binding production gagal tertutup bila OpenBao sealed/tidak tersedia atau MinIO tidak mengembalikan VersionId. Infrastruktur satu VM dapat dipakai, tetapi risiko kehilangan satu disk/VM belum ditutup tanpa backup eksternal.

## Deployment checklist

1. Backup database/object store dan buktikan restore; catat checksum serta ticket.
2. Deploy kode dalam maintenance window tanpa mengubah `.env` melalui repository.
3. Jalankan `php artisan migrate --force`; migration: `000001` challenge, `000002` ceremony/evidence/outbox, `000003` key/seal/bundle, `000004` audit/WORM receipt, `000005` status event.
4. Terapkan permission SQL Server oleh DBA terpisah dan uji negative `UPDATE`/`DELETE` dengan credential aplikasi.
5. Injeksi secret/provider references ke `.env` permission `600`; validasi key fingerprint melalui kanal resmi dan retention lock melalui provider API.
6. Jalankan `config:cache`, lalu pastikan `bootstrap/cache/config.php` dimiliki deploy-user dengan group PHP-FPM dan mode `640` karena file cache memuat secret. Lanjutkan `route:cache`, queue/scheduler restart, health check, signing canary non-produksi, offline bundle verify, audit verify, dan evidence reconcile.
7. Aktifkan traffic bertahap; pantau alert OTP/KMS/WORM/audit selama change window.

## Rollback

Rollback aplikasi tidak boleh menghapus evidence, signature, audit, checkpoint, status event, atau object WORM. Hentikan signing baru, pertahankan verifier/read path, lalu deploy build sebelumnya yang kompatibel. Rollback schema hanya boleh pada database test atau production kosong sebelum ada evidence v2; setelah bukti v2 ada, gunakan forward fix. Permission/WORM retention tidak dicabut sebagai bagian rollback aplikasi.

## Risiko residu yang diterima hanya oleh owner organisasi

OTP email tetap rentan phishing/takeover; waktu masih internal dan belum RFC 3161; ZIP signature bukan embedded CMS/PAdES; administrator lintas DB+KMS+WORM/SIEM yang berkolusi berada di luar kontrol satu aplikasi; pengujian workspace memakai SQLite dan fake signer/store; public key channel dan retention nyata belum dipilih.

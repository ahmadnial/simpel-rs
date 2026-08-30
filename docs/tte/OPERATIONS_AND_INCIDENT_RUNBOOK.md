# Operations and incident runbook

## Setelah VM atau container restart

MinIO otomatis aktif kembali dan data tetap berada pada Docker volume. OpenBao sengaja kembali `sealed`; ini bukan kerusakan, melainkan perlindungan agar pencurian VM saja tidak otomatis membuka private key.

1. Pastikan hanya operator berwenang yang dapat membaca `/home/nialapps/keyOpenBao.md` (mode `600`). Pindahkan salinan recovery ke media/password vault di luar VM sesegera mungkin.
2. Jalankan dari `/opt/simpel-tte`: `./unseal-openbao.sh`. Masukkan tiga dari lima unseal shares saat diminta; input tidak tampil dan tidak masuk shell history.
3. Verifikasi `docker exec simpel-tte-openbao bao status`; hasil harus `Sealed false`.
4. Pastikan MinIO sehat melalui `curl -fsS http://127.0.0.1:9000/minio/health/live` dan jalankan canary aplikasi.

Jangan membuat auto-unseal dengan menyimpan tiga shares di VM yang sama. Itu menghilangkan manfaat pemisahan kunci. Jika kelak diperlukan startup tanpa operator, gunakan KMS/HSM eksternal untuk auto-unseal.

## Monitoring minimum

Scheduler dan worker harus aktif. `tte:audit-verify --json` berjalan tiap 15 menit; `evidence:reconcile --json` setiap hari 02:30. Arahkan `TTE_SECURITY_LOG_CHANNEL` ke SIEM dan alert-kan event `otp_send_failed`, `otp_verification_failed`, `audit_chain_verification_failed`, `worm_readback_mismatch`, `evidence_reconciliation_failed`, perubahan akun/role/delegasi/workflow/key, dan perubahan status evidence.

Pada VM saat ini keduanya disupervisi oleh `simpel-rs-queue.service` dan `simpel-rs-scheduler.timer`. Periksa dengan `systemctl status simpel-rs-queue.service simpel-rs-scheduler.timer`; setelah deployment kode jalankan `php artisan queue:restart` agar worker memuat versi terbaru.

Tambahkan rule SIEM untuk lonjakan OTP/resend/lockout, signing di luar pola, lookup/upload verifier, clock drift, perubahan policy KMS/WORM, serta percobaan `UPDATE`/`DELETE` yang ditolak SQL Server/Object Lock. Metadata sensitif harus tetap berupa hash atau `[REDACTED]`.

## Respons insiden

1. Buka ticket, tetapkan incident commander, legal/security/infrastructure owner, dan catat waktu UTC. Jangan mengedit bukti lama.
2. Kompromi akun/mailbox: nonaktifkan akun, cabut sesi, challenge, dan ceremony aktif; rotasi password/recovery channel melalui maker-checker; review signing sejak waktu paparan.
3. Kompromi key: hentikan signing, tandai key revoked melalui registry/change terkontrol, publikasikan status di kanal resmi, verifikasi seluruh evidence pada rentang terdampak, lalu lakukan key ceremony baru di KMS/HSM.
4. Hash/checkpoint/WORM mismatch: isolasi node, hentikan publish signing, jalankan `php artisan tte:audit-verify --json` dan `php artisan evidence:reconcile --json`, bandingkan checkpoint/domain salinan, jangan “memperbaiki” row/object lama.
5. Salah tanda tangan/pencabutan administratif: jalankan sebagai operator berwenang `php artisan evidence:set-status <uuid> revoked --reason="..." --reference="TICKET" --force`. Untuk supersede wajib `--related=<evidence-uuid-baru>`. Perintah membuat audit event/checkpoint; bukti lama tetap utuh.
6. Kehilangan database/storage: pulihkan ke lingkungan isolasi, impor public key dari kanal resmi, lalu verifikasi setiap ZIP dengan `php artisan evidence:verify-bundle <zip> --public-key=<key.json> --json`. Cocokkan receipt/checkpoint sebelum membuka layanan.
7. Ekspor sengketa: salin bundle, public key resmi, status/revocation history, audit checkpoint dan receipt WORM. Rekam checksum media ekspor dan chain of custody.

## Restore drill

Minimal triwulanan: pulihkan backup database ke jaringan terisolasi, restore salinan object ke bucket/tenant berbeda tanpa mengubah versi sumber, jalankan offline verifier untuk sampel dan seluruh evidence berisiko tinggi, lalu rekonsiliasi jumlah/hash/size/version/retention. Drill dinyatakan gagal jika public key resmi tidak tersedia, ada gap audit, atau satu artefak berbeda. Simpan laporan/ticket; jangan simpan credential di laporan.

## Credential exposure

Password literal yang sebelumnya berada pada production-style seeder harus dianggap terpapar. Operator wajib merotasi seluruh akun yang pernah menggunakan password tersebut, mencabut sesi, dan meninjau log akses. Kode sekarang membuat bootstrap password acak yang tidak ditampilkan; aktivasi memakai reset password. Agen tidak dapat melakukan rotasi akun/mailbox eksternal.

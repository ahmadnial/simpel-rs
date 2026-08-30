# TTE Internal v2 — indeks dokumentasi

Titik masuk tunggal untuk memahami status program penguatan bukti TTE (`internal_strong_otp_v2`). Baca ini dulu sebelum file lain.

## Status per 30 Agustus 2026 (terverifikasi ulang, bukan klaim lama)

**Kode aplikasi: selesai dan lulus test.** 52 test Feature/Unit terkait TTE dijalankan ulang hari ini dan **semuanya lulus** (`AuditChainAndImmutableStorageTest`, `CredentialLeakGuardTest`, `EvidenceBundleVerificationTest`, `ProductionEvidenceAdaptersTest`, `PublicPdfVerificationTest`, `SigningCeremonyStateMachineTest`, `SigningOtpV2Test`, `TteBaselineCharacterizationTest`, `TteTargetGapContractTest`, `CanonicalJsonTest`, `SecurityEventReporterTest`). Artinya Paket A–F dari rencana (`../../TTE_INTERNAL_STRONG_OTP_FINAL_PLAN.md` §19.3) sudah terimplementasi di level aplikasi:

- Challenge OTP v2 terikat transaksi (`SigningOtpService`, tabel `signature_otp_challenges`).
- State machine signing ceremony + manifest kanonis RFC 8785 (`signing_ceremonies`, `signature_evidence`).
- Segel institusi via interface `EvidenceSigner`/`SigningKeyRegistry` + adapter OpenBao.
- Evidence bundle deterministik + verifier online/upload/offline CLI.
- Audit hash-chain append-only + checkpoint bertanda tangan + adapter MinIO Object Lock.
- Command operasional: `tte:audit-verify`, `evidence:reconcile`, `evidence:verify-bundle`, `evidence:set-status`.

**Yang BELUM selesai bukan kode — tapi keputusan/aksi organisasi di luar repo.** Ini yang bikin proyek "tidak jadi-jadi": bagian coding sudah tuntas, sisanya adalah gate deployment yang hanya bisa dibuka oleh manusia (DBA, legal, infra owner), bukan oleh agen. Daftar lengkap ada di [DEPLOYMENT_HANDOFF.md](DEPLOYMENT_HANDOFF.md) bagian "Gate produksi wajib" — ringkasnya:

1. DBA menerapkan permission SQL Server append-only ([SQLSERVER_APPEND_ONLY_PERMISSIONS.md](SQLSERVER_APPEND_ONLY_PERMISSIONS.md)) — belum dijalankan di production.
2. Rotasi semua credential yang pernah plaintext di seeder lama (kode sudah dibersihkan, tapi rotasi akun nyata harus dilakukan operator).
3. Dual control atas OpenBao unseal shares + backup/restore off-VM untuk MinIO — masih single-VM.
4. Kanal resmi publikasi public key/fingerprint — belum ditetapkan.
5. Maker-checker untuk perubahan email direktur, SOP delegasi, review legal/retensi — belum disahkan.
6. Security review/penetration test independen — belum dijalankan.

## Peta dokumen

| Dokumen | Isi | Baca kalau ingin tahu |
|---|---|---|
| [ARCHITECTURE_AND_THREAT_MODEL.md](ARCHITECTURE_AND_THREAT_MODEL.md) | Data flow, ADR ringkas, tabel ancaman/kontrol, klaim yang diizinkan di UI | Bagaimana sistem ini bekerja dan kenapa didesain begitu |
| [DEPLOYMENT_HANDOFF.md](DEPLOYMENT_HANDOFF.md) | Deliverable, gate produksi, checklist deploy, rollback, risiko residu | Apa yang harus dicek/dilakukan sebelum go-live |
| [OPERATIONS_AND_INCIDENT_RUNBOOK.md](OPERATIONS_AND_INCIDENT_RUNBOOK.md) | Restart VM/unseal OpenBao, monitoring, respons insiden, restore drill | Apa yang dilakukan operator sehari-hari / saat insiden |
| [SECURITY_TEST_MATRIX.md](SECURITY_TEST_MATRIX.md) | Peta test otomatis ke area ancaman + batasnya | Bukti mana yang sudah diuji vs. masih butuh uji manusia |
| [SQLSERVER_APPEND_ONLY_PERMISSIONS.md](SQLSERVER_APPEND_ONLY_PERMISSIONS.md) | Skrip SQL append-only untuk DBA | Cara DBA mengunci tabel bukti agar app tidak bisa UPDATE/DELETE |

Rencana asli lengkap (905 baris, sudah dijadikan acuan eksekusi bertahap): [`TTE_INTERNAL_STRONG_OTP_FINAL_PLAN.md`](../../TTE_INTERNAL_STRONG_OTP_FINAL_PLAN.md) di root repo — dokumen ini tidak perlu dibaca ulang seluruhnya untuk kerja sehari-hari, cukup jadi rujukan bila ada keputusan scope yang perlu diverifikasi.

## Untuk siapa pun yang lanjut kerja di sini

- Bagian aplikasi/kode: **jangan mulai dari nol lagi**. Cek dulu apakah kebutuhan sudah ada di `app/Services/`, `app/Contracts/`, atau test yang sudah lulus, sebelum menambah file baru.
- Bagian yang tersisa didominasi keputusan infrastruktur/organisasi (lihat gate di atas), bukan pekerjaan koding baru. Menambah kode tanpa keputusan itu tidak akan memajukan status "selesai".
- Definition of Done aplikasi vs. Definition of Done production adalah dua hal berbeda (lihat §16 rencana). Aplikasi sudah memenuhi yang pertama; yang kedua menunggu owner organisasi.

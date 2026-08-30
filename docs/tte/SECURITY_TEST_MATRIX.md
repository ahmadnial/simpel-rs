# Security test matrix

| Area | Automated evidence | Status/limit |
|---|---|---|
| PDF/manifest/signature tamper | `EvidenceBundleVerificationTest`, `PublicPdfVerificationTest`, `CanonicalJsonTest` | Lulus pada test adapter |
| OTP replay/binding/resend/expiry/attempt/rate limit | `SigningOtpV2Test`, `WorkflowSecurityTest` | Lulus; multi-node production load test masih gate staging |
| State failure/idempotency | `SigningCeremonyStateMachineTest`, E2E, numbering collision | Lulus untuk render/storage/KMS/WORM injection |
| Audit mutation/delete/reorder/checkpoint | `AuditChainAndImmutableStorageTest`, `tte:audit-verify` | Lulus pada SQLite; permission SQL Server perlu DBA |
| WORM overwrite/read-back/restore | `AuditChainAndImmutableStorageTest`, `evidence:reconcile` | Fake write-once lulus; Object Lock production belum tersedia |
| Offline verification/key states | `EvidenceBundleVerificationTest`, CLI verifier | Lulus untuk active/retired/revoked test keys |
| Upload verifier | `PublicPdfVerificationTest` | Size, MIME/magic, active tokens, byte mismatch lulus; external parser fuzzing tetap direkomendasikan |
| Secret/log guard | `CredentialLeakGuardTest`, `SecurityEventReporterTest` | Seeder dibersihkan; rotasi eksternal wajib |
| Human dispute/legal/penetration test | Runbook dan handoff | Wajib dilakukan owner independen; bukan automated proof |

Definition of Done untuk adapter aplikasi dapat dinilai dari suite. Definition of Done production/organisasi belum terpenuhi sampai seluruh gate eksternal pada handoff ditandatangani.

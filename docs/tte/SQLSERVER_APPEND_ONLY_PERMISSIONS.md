# SQL Server append-only permissions

Runbook ini dijalankan oleh DBA setelah migration v2 selesai, memakai principal aplikasi yang sebenarnya. Jangan menjalankannya dari credential aplikasi dan jangan menyalin nama principal produksi ke repository.

## Prasyarat

- Backup dan restore drill terbaru telah lulus.
- Principal aplikasi dan principal migration/DBA terpisah.
- KMS signer serta WORM Object Lock sudah aktif dan retention policy disahkan.
- Ganti `[SIMPEL_RS_APP_PRINCIPAL]` secara manual setelah DBA memverifikasi target database.

## Permission baseline

Jalankan dalam transaction eksplisit dan review hasil `fn_my_permissions` sebelum commit.

```sql
BEGIN TRANSACTION;

GRANT SELECT, INSERT ON OBJECT::dbo.audit_chain_events TO [SIMPEL_RS_APP_PRINCIPAL];
DENY UPDATE, DELETE ON OBJECT::dbo.audit_chain_events TO [SIMPEL_RS_APP_PRINCIPAL];

GRANT SELECT, INSERT ON OBJECT::dbo.audit_checkpoints TO [SIMPEL_RS_APP_PRINCIPAL];
DENY UPDATE, DELETE ON OBJECT::dbo.audit_checkpoints TO [SIMPEL_RS_APP_PRINCIPAL];

GRANT SELECT, INSERT ON OBJECT::dbo.evidence_storage_copies TO [SIMPEL_RS_APP_PRINCIPAL];
DENY UPDATE, DELETE ON OBJECT::dbo.evidence_storage_copies TO [SIMPEL_RS_APP_PRINCIPAL];

GRANT SELECT, INSERT ON OBJECT::dbo.signature_evidence TO [SIMPEL_RS_APP_PRINCIPAL];
DENY UPDATE, DELETE ON OBJECT::dbo.signature_evidence TO [SIMPEL_RS_APP_PRINCIPAL];

GRANT SELECT, INSERT ON OBJECT::dbo.evidence_status_events TO [SIMPEL_RS_APP_PRINCIPAL];
DENY UPDATE, DELETE ON OBJECT::dbo.evidence_status_events TO [SIMPEL_RS_APP_PRINCIPAL];

GRANT SELECT, INSERT ON OBJECT::dbo.document_signatures TO [SIMPEL_RS_APP_PRINCIPAL];
DENY UPDATE, DELETE ON OBJECT::dbo.document_signatures TO [SIMPEL_RS_APP_PRINCIPAL];

-- Writer perlu mengunci dan mengubah hanya head stream; event tetap append-only.
GRANT SELECT, INSERT, UPDATE ON OBJECT::dbo.audit_chain_streams TO [SIMPEL_RS_APP_PRINCIPAL];
DENY DELETE ON OBJECT::dbo.audit_chain_streams TO [SIMPEL_RS_APP_PRINCIPAL];

SELECT * FROM fn_my_permissions('dbo.audit_chain_events', 'OBJECT');
SELECT * FROM fn_my_permissions('dbo.signature_evidence', 'OBJECT');

-- COMMIT hanya setelah output diverifikasi oleh DBA kedua.
ROLLBACK TRANSACTION;
```

Ganti `ROLLBACK` dengan `COMMIT` hanya dalam change window yang disetujui. `DENY` tidak menggantikan pemisahan owner schema: principal aplikasi tidak boleh menjadi `db_owner`, `db_ddladmin`, atau pemilik schema `dbo`.

## Uji pascapenerapan

Dengan credential aplikasi, pastikan `SELECT` dan append yang sah berhasil, sedangkan `UPDATE`/`DELETE` pada tabel bukti gagal. Dengan credential migration terpisah, jalankan smoke test migration. Catat hasil, approver, waktu UTC, dan ticket perubahan tanpa menyimpan credential.

Rollback permission hanya boleh dilakukan DBA melalui ticket insiden/change yang disetujui. Bukti yang sudah ditulis tidak boleh dihapus saat rollback aplikasi.

# Audit lanjutan workflow dokumen — 11 November 2026

## Hasil

Ditemukan **6 kelompok bug aplikasi tambahan yang berhasil direproduksi**, serta **1 cacat schema pengujian SQLite**. Temuan sebelumnya tentang MIME worker, akun tanpa unit, dan sumber draft yang tidak ditemukan tetap berlaku; tidak dihitung ulang sebagai temuan baru.

Audit ini menyiapkan bahan perbaikan, tidak menerapkan perbaikan aplikasi atau deployment. Production hanya diperiksa dengan SELECT, metadata schema, dan checksum kode. Semua request yang mengubah status, upload sintetis, delegasi, dan tanda tangan dijalankan pada SQLite `:memory:`, fake storage, fake notifikasi, dan adapter signing pengujian.

Tidak ada klaim bahwa seluruh workflow sudah flawless. Masih ada bug terkonfirmasi, suite lama yang belum bersih, dan pengujian concurrency SQL Server/operasi signing nyata yang belum dilakukan.

## Bukti dan cara mengulang

```sh
# Baseline suite yang sudah ada; kode aplikasi tidak diubah.
php vendor/bin/phpunit --do-not-cache-result

# Acceptance tests audit, secara eksplisit; masih merah sampai bug diperbaiki.
php vendor/bin/phpunit --do-not-cache-result tests/Investigation/DocumentWorkflowAuditTest.php
```

- Baseline: **152 tests, 1.047 assertions, 18 errors, 6 failures**. Sebanyak 128 tes tidak melaporkan error/failure.
- Audit tambahan: **21 tests, 166 assertions, 14 failures, 0 errors; 7 tes lulus**. Empat belas failure mewakili beberapa variasi dari enam kelompok bug aplikasi dan satu cacat schema test, bukan 14 bug independen.
- Integrasi LibreOffice nyata: **2 tests, 5 assertions, lulus** pada run eksklusif. Uji memeriksa layout/koordinat teks pada PDF bertanda dan bersih, serta round trip DOC → DOCX → PDF; ini bukan sertifikasi seluruh dokumen pengguna.
- PHP lint dan Laravel Pint pada file tes audit: lulus.
- Ringkasan bukti mesin: `workflow-audit-2026-11-11/workflow-baseline.json`, `workflow-audit-2026-11-11/audit-regressions.json`, dan `workflow-audit-2026-11-11/audit-office.json`.

Tes audit ditempatkan di `tests/Investigation`, di luar dua direktori suite default pada `phpunit.xml`, agar acceptance tests yang sengaja mengungkap bug belum diperbaiki tidak diam-diam mengubah baseline suite. File tes berisi guard yang memastikan SQLite in-memory. Sesudah perbaikan, acceptance tests harus lulus dan dapat dimasukkan ke suite utama.

## F01 — Mutasi publikasi tanpa otorisasi [P1]

**Reproduksi:** pengguna aktif dari unit lain, tanpa role/permission publikasi dan tanpa akses dokumen terbatas, mengirim request publikasi, unpublish, atau republish terhadap ID dokumen sintetis.

**Hasil aktual:** ketiga request mendapat 302 sukses dan benar-benar mengubah status:

| Aksi | Status awal | Status sesudah request tanpa izin |
| --- | --- | --- |
| publikasi | ditandatangani | dipublikasikan |
| unpublish | dipublikasikan | ditarik |
| republish | ditarik | dipublikasikan |

Bukan sekadar halaman tombol terlihat: mutasi database berhasil. Scope dapat diubah menjadi `internal` oleh pengguna tersebut. Potensi dampaknya adalah pembukaan akses dokumen atau penarikan publikasi tanpa wewenang.

**Akar:** grup route `publikasi` hanya memakai middleware `auth`; `PublikasiController` melakukan validasi input tanpa Gate/permission. `DocumentService::{publikasi,unpublish,republish}` memeriksa status tetapi tidak wewenang aktor.

**Tes:** `test_unrelated_user_without_publication_permission_is_forbidden`, tiga dataset.

**Arah perbaikan:** wajibkan izin publikasi pada semua endpoint baca/mutasi yang relevan, dengan scope sesuai kebijakan organisasi. Pertahankan validasi status di bawah lock. Tambahkan kasus pengguna tanpa role, lintas unit, auditor, publikator sah, dan scope dokumen rahasia. Penyembunyian menu saja tidak cukup.

## F02 — Pengusul dapat menyetujui dokumennya sendiri [P1]

**Reproduksi yang berhasil:**

1. Pengusul memiliki role asesor dan masuk pool otomatis berbasis role.
2. Pengusul ditambahkan sebagai user eksplisit dalam pool paralel.
3. Pengusul memiliki role pemeriksa pada step serial otomatis.
4. Pengusul menjadi delegasi aktif pemeriksa yang memiliki tiket untuk dokumennya.

**Hasil aktual:** pemilik dokumen dapat melakukan approval; tiket menjadi `disetujui` dan dokumen maju ke `menunggu_ttd`. Keempat variasi gagal terhadap acceptance criteria larangan self-approval.

**Akar:** pemeriksaan pengusul berbeda dari pemeriksa hanya ada pada pemilihan verifikator manual. Resolusi pool otomatis tidak mengecualikan pengusul, dan guard aksi tidak menolak `actor.id == document.pengusul_id`. Delegasi melewati kebutuhan pengusul memiliki role asesor secara langsung.

**Tes:** tiga dataset `test_proposer_cannot_approve_own_document_through_automatic_pool` dan `test_proposer_cannot_approve_own_document_as_delegate_of_assigned_reviewer`.

**Arah perbaikan:** validasi pada resolusi penerima dan pada guard keputusan di dalam transaksi; evaluasi pemilik tiket maupun aktor aktual/delegasi. Sesudah mengecualikan pengusul, validasi ulang kecukupan kuorum agar dokumen tidak dikirim ke tahap yang mustahil selesai.

## F03 — Satu aktor dihitung sebagai beberapa pemeriksa melalui delegasi [P1]

**Reproduksi A, kuorum:** step membutuhkan dua persetujuan, tiket dimiliki A dan B. B menjadi delegasi A lalu menyetujui tiket B dan tiket A.

**Hasil:** dokumen maju ke `menunggu_ttd` meskipun hanya ada **satu `decided_by_user_id` unik** di antara dua approval.

**Reproduksi B, pemisahan level:** B bertindak sebagai delegasi A pada level 1, kemudian bertindak sebagai pemilik tiket B pada level 2.

**Hasil:** orang B yang sama menyetujui kedua level dan dokumen maju ke `menunggu_ttd`, walaupun kode menyatakan pemeriksa level berbeda harus berbeda.

**Akar:** kuorum menghitung jumlah tiket yang disetujui; pengecualian pemeriksa antartahap dan `hasLowerLevelAssignment()` memakai `verifikator_id`, tidak identitas aktual pada `decided_by_user_id`. Pencatatan aktor sudah ada tetapi belum dipakai secara konsisten untuk pemisahan tugas.

**Tes:** `test_quorum_requires_distinct_actual_decision_makers_when_one_is_delegate` dan `test_actual_lower_level_actor_cannot_also_approve_upper_level`.

**Arah perbaikan:** definisikan dan tegakkan identitas pemeriksa aktual di bawah lock. Persetujuan kedua oleh orang yang sama tidak boleh memenuhi kuorum tambahan atau pemisahan level. Untuk data lama dengan actor kosong, tetapkan fallback identitas yang kompatibel dan eksplisit. Validasi kemampuan pool dan delegasi agar konfigurasi yang mustahil memenuhi kebijakan ditolak lebih awal.

## F04 — Akun nonaktif dengan sesi yang masih berlaku tetap bisa menyetujui [P1]

**Reproduksi:** pemeriksa menerima tiket ketika aktif, lalu `is_active` diubah menjadi false. Request berikutnya dimodelkan dengan user hasil rehidrasi dan sesi terautentikasi yang masih berlaku.

**Hasil:** approval sukses; tiket menjadi `disetujui`, dokumen menjadi `menunggu_ttd`.

**Akar:** `LoginController` memeriksa aktif saat login, tetapi middleware request hanya `auth`. Guard keputusan hanya memeriksa kepemilikan/delegasi tiket. Perubahan keamanan akun mencabut OTP/signing context, bukan otorisasi semua sesi verifikasi.

**Tes:** `test_deactivated_verifier_session_cannot_approve`.

**Arah perbaikan:** periksa status aktif pada setiap request terautentikasi yang relevan dan di guard transisi penting. Cabut sesi sesuai kebijakan deaktivasi; hindari mengandalkan larangan login baru untuk menghentikan sesi lama. Uji deactivation setelah halaman dibuka dan sebelum request dikirim. Respons logout/401/403 boleh dipilih sesuai konvensi, tetapi status tiket/dokumen harus tetap tidak berubah.

## F05 — Pool user eksplisit menerima pemeriksa nonaktif [P2]

**Reproduksi:** satu-satunya anggota pool user eksplisit dinonaktifkan sebelum pengajuan.

**Hasil:** pengajuan tetap sukses menjadi `diajukan`, dengan tiket kepada pemeriksa nonaktif. Pengajuan seharusnya ditolak secara jelas karena tidak ada pemeriksa aktif yang dapat memenuhi tahap.

**Akar:** cabang pool berbasis role memfilter `is_active=true`, cabang pool `tipe_pool=user` hanya memeriksa relasi user ada. Penghitungan kecukupan kuorum dilakukan setelah memasukkan user nonaktif.

**Tes:** `test_direct_pool_does_not_assign_inactive_user`.

**Arah perbaikan:** satukan aturan kelayakan penerima untuk semua jenis pool, filter sebelum hitung kuorum, dan tolak pengajuan secara atomik jika anggota layak kurang. Putuskan penanganan tiket yang sudah terbit ketika user kemudian dinonaktifkan; jangan otomatis menugaskan orang lain tanpa kebijakan.

## F06 — Rollback database tidak mengembalikan snapshot PDF [P1]

Dua bukti berbeda dari akar yang sama:

1. **Kegagalan biasa:** preview sah dibuat, lalu pool dikosongkan sebelum submit. Endpoint mengembalikan 422, dokumen tetap draft, `file_pdf_path` rollback ke NULL, tetapi `documents/{id}/reviewed/{version}.pdf` tetap tertulis di storage.
2. **Pengajuan tumpang tindih:** dua receipt sah dibuat untuk sumber/versi sama tetapi PDF mode penanda berbeda. Tes memakai hook `Gate::after` untuk menjadwalkan commit A tepat setelah B lolos policy. A menyimpan snapshot dan mengajukan dokumen. B masuk transaksi, menimpa snapshot, kemudian ditolak 403 karena dokumen sudah diajukan. Setelah penolakan, DB masih menunjuk snapshot A, tetapi hash/bytes file sudah menjadi PDF B.

**Akar:** `DocumentController::ajukan` menulis path snapshot tetap pada baris 564–565 sebelum service memvalidasi status workflow; lock dan rollback SQL tidak melakukan rollback filesystem. Kelayakan dokumen yang dibaca sesudah lock tidak divalidasi sebelum penulisan. Ini bukan klaim PDF resmi bertanda tangan tertimpa: path yang terbukti terpengaruh adalah PDF review pada versi pengajuan.

**Tes:** `test_failure_to_create_workflow_tickets_does_not_persist_review_snapshot` dan `test_second_pre_authorized_submission_cannot_overwrite_committed_snapshot`.

**Batas bukti:** interleaving dibuktikan deterministik pada SQLite dengan hook, bukan load test dua koneksi SQL Server. Tes tidak mengarang lock behavior engine; ia menguji efek samping sesudah state precondition berubah pada titik yang ditunjukkan kode.

**Arah perbaikan:** validasi otorisasi/status/versi di bawah lock sebelum efek samping; gunakan path snapshot yang immutable/unik dan protokol commit/cleanup yang aman. Request yang gagal harus membersihkan hanya berkas miliknya, tidak boleh menghapus atau menimpa snapshot request yang sudah sukses. Menambah `delete($snapshot)` pada catch tanpa identitas file yang unik berisiko menghapus hasil request lain.

## T01 — Migrasi SQLite menghilangkan filter indeks tiket aktif [cacat pengujian terkonfirmasi]

Schema akhir SQLite mempunyai unique index pada `(document_id, document_version_id, workflow_step_id, verifikator_id, level)` **tanpa WHERE**. Akibatnya membuat putaran baru untuk kombinasi yang sudah punya riwayat approved/cancelled melanggar unique constraint.

Migration awal mendefinisikan filter `WHERE status = 'menunggu'`. Dalam rangkaian migrasi, perubahan tabel SQLite membangun ulang indeks tanpa predicate. Metadata SQLite final, bukan sekadar teks migration, diperiksa oleh tes.

**Pembedaan penting:** metadata `sys.indexes` production SQL Server menunjukkan `has_filter=1` dan predicate `([status]='menunggu')`. Jadi cacat schema final ini **tidak terbukti ada di production**; jangan menghapus/mengubah indeks production untuk memperbaiki kegagalan SQLite.

**Tes:** `test_local_migrations_preserve_filtered_active_ticket_index`.

Untuk tiga tes round trip pada audit, helper `alignTicketIndexToProduction()` mengembalikan filtered index pada database SQLite pengujian saja. Hal ini dinyatakan eksplisit, tidak mengubah migration aplikasi atau production, dan cacat schema aslinya tetap mempunyai acceptance test merah tersendiri. Dengan filter yang sesuai production, tiga pengembalian antarlevel, tiga penolakan signer, dan revisi setelah partial quorum dapat selesai dalam tes.

## Matriks skenario dan hasil

| Skenario | Hasil audit |
| --- | --- |
| Pengajuan dengan receipt, approval, OTP, signing, publikasi berizin, withdraw, republish | Lulus dengan adapter PDF/signing/notification pengujian; hash PDF signature dicocokkan |
| Tiga pengembalian level 2 → level 1 → level 2 | Lulus setelah schema SQLite diselaraskan dengan filter production |
| Tiga penolakan signer dan pembukaan ulang tiket level terakhir | Lulus setelah penyelarasan index; keputusan lama tetap immutable |
| Revisi setelah satu approval pada kuorum dua | Lulus; approval versi lama tidak memenuhi kuorum versi baru |
| Replay tiket yang sudah disetujui | Ditolak 409, sesuai harapan |
| Tiket lama setelah revisi dan pergantian sumber | Ditolak 409; receipt sumber lama ditolak 422 |
| Preview privat/upload pengganti oleh unit lain | Ditolak 403, sesuai harapan |
| Publikasi/unpublish/republish tanpa izin | **Bug F01**, 3 variasi |
| Self-approval melalui 3 mode pool otomatis dan delegasi | **Bug F02**, 4 variasi |
| Kuorum dan pemisahan level berdasarkan aktor delegasi | **Bug F03**, 2 variasi |
| Sesi pemeriksa setelah deaktivasi | **Bug F04** |
| Pengajuan ke pool user nonaktif | **Bug F05** |
| Kegagalan pengajuan biasa dan pengajuan tumpang tindih | **Bug F06**, 2 variasi |
| Schema SQLite vs predicate index production | **Cacat T01** |

Jumlah di atas tidak mencakup seluruh kombinasi status/role/file/time/race yang mungkin. Baseline juga mencakup OTP expiry/resend/session binding, tetapi banyak fixture lama belum mencapai assertion karena sumber DOCX tidak dibuat. Pengujian itu harus diperbaiki sebelum dipakai sebagai bukti cakupan OTP yang lengkap.

## Silang-periksa production

Checksum sembilan berkas VM sama persis dengan workspace: `routes/web.php`, `bootstrap/app.php`, `DocumentService`, `DocumentVerification`, `VerifikasiController`, `PublikasiController`, admin `UserController`, `NotificationController`, dan `AppServiceProvider`. Controller pengajuan juga telah cocok pada investigasi pertama. Dengan demikian akar kode yang diuji ada pada release production, walaupun tidak semua kondisi pemicunya sedang terjadi.

Query baca menemukan:

- Tidak ada dokumen `diajukan`/`dalam_verifikasi`/`ditolak_penandatangan` yang kehilangan seluruh tiket menunggu kepada user aktif pada versi/current step yang sesuai. Ini pemeriksaan dasar, bukan evaluasi seluruh syarat actionable/delegasi/kuorum.
- Tidak ada riwayat tiket nondeleted yang assigned user atau aktor keputusannya sama dengan pengusul.
- Tidak ada pengusul dokumen saat ini yang memegang role asesor internal.
- Tidak ada referensi pool aktif ke user nonaktif dan tidak ada tiket menunggu milik user nonaktif.
- Sepuluh step paralel aktif semuanya mempunyai `min_approval=1`; tidak ada delegasi yang aktif pada rentang tanggal pemeriksaan. F03 tetap cacat yang direproduksi untuk skenario yang didukung fitur, bukan insiden historis yang ditemukan pada data ini.
- Seluruh dokumen `menunggu_ttd` memiliki minimal satu user aktif yang memegang role signer pada workflow.
- Kolom `audit_logs.deskripsi` dan `document_verifications.direset_alasan` sudah `nvarchar(max)`. Error truncation historis pada log tidak boleh dihitung sebagai bug schema production yang masih aktif.

Tidak dilakukan exploit/approval/publikasi untuk membuktikan temuan pada production. Agregat di atas tidak menunjukkan semua gangguan sudah tertutup; misalnya tidak memeriksa validitas seluruh PDF resmi atau kesehatan layanan signing eksternal.

## Prioritas perbaikan dan bukti penerimaan

1. Perbaiki MIME worker dan penghalang pengajuan dari laporan pertama.
2. Tutup F01, F02, F03, dan F04 sebelum mengandalkan kontrol akses/pemisahan persetujuan.
3. Perbaiki atomicity snapshot F06 sebelum menguji double submit pada staging SQL Server.
4. Samakan eligibility pool F05 dan penanganan unit kosong; pulihkan sumber draft yang benar lewat prosedur terkontrol.
5. Perbaiki schema SQLite T01 dan fixture sumber DOCX pada tes lama. Seluruh baseline dan acceptance tests harus lulus dengan semantik yang benar, bukan menghapus assertion atau membuka guard.
6. Staging SQL Server: dua koneksi independen untuk approve vs approve, approve vs revisi, duplicate submit dengan dua receipt berbeda, upload vs submit, signer return vs approval, dan retry setelah fault storage. Pastikan hanya satu transisi sah, tidak ada tiket menunggu duplikat, riwayat tidak berubah, dan hash snapshot/signature tidak berubah oleh request yang kalah.
7. Browser melalui Nginx yang sama: dokumen baru → preview → submit → revisi → upload ulang → preview → resubmit; uji back/forward, receipt expiry, timeout/retry, dan label/status kedua tombol. Gunakan akun dan dokumen uji yang disetujui.
8. Signing operasional: verifikasi renderer/font, pengiriman OTP, provider signer, immutable storage, dan recovery outbox di lingkungan uji yang mewakili production. Keberhasilan adapter test tidak membuktikan layanan tersebut siap pada VM.

Perubahan yang dibuat oleh audit hanya file tes dan laporan/bukti lokal. Perbaikan production, migrasi, perubahan akun, reload Nginx, dan deployment belum dilakukan.


## Tindak lanjut implementasi

Perbaikan dan hasil QC terbaru dicatat pada `2026-11-11-backend-fixes.md`.
Acceptance tests yang sebelumnya berada di `tests/Investigation/DocumentWorkflowAuditTest.php`
sudah dipromosikan menjadi `tests/Feature/DocumentWorkflowRegressionTest.php`, dengan
kasus tambahan. Jalankan suite default atau file baru tersebut; perintah lama di atas
merupakan catatan historis audit sebelum perbaikan.

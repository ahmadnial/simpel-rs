# Rencana Final Penguatan Bukti TTE Internal Berbasis OTP Tanpa PSrE

Status dokumen: **FINAL—siap dieksekusi agen, belum diimplementasikan**  
Sasaran: SIMPEL-RS  
Profil implementasi: **`internal_strong_otp_v2` (non-PSrE)**  
Metode persetujuan direktur: **OTP email yang diperkuat dan terikat transaksi**  
Tanggal analisis: 29 Agustus 2026

## 1. Ringkasan keputusan

Sistem saat ini sudah memiliki fondasi yang berguna: OTP terikat dokumen, pemeriksaan peran pada workflow, PDF final, hash SHA-256, QR verifikasi, serta audit aktivitas. Namun bukti yang ada masih berada dalam satu domain kepercayaan yang sama—database, file PDF, hash, QR, dan halaman verifikasi dikelola oleh aplikasi/server yang sama. Administrator atau penyerang yang menguasai server dan database berpotensi mengganti PDF sekaligus hash dan catatan pendukungnya.

Target penguatan bukan sekadar menambah QR atau watermark. Bukti yang kuat harus dapat menjawab secara terpisah:

1. **Dokumen mana** yang ditandatangani, hingga tingkat byte.
2. **Siapa** yang melakukan tindakan tanda tangan.
3. **Apa yang disetujui** oleh penandatangan pada saat itu.
4. **Kapan** tindakan terjadi dan seberapa dapat dipercaya waktunya.
5. **Apakah bukti berubah** setelah penandatanganan.
6. **Apakah bukti tetap dapat diverifikasi** bila aplikasi atau database utama tidak tersedia.

Tiga investasi dengan dampak tertinggi adalah:

1. Manifest bukti kanonis yang ditandatangani dengan kunci kriptografi institusi di luar database aplikasi.
2. Upacara penandatanganan OTP yang terikat langsung pada hash PDF, versi, identitas direktur, sesi, nonce, dan masa berlaku.
3. Penyimpanan WORM/Object Lock, audit hash-chain, serta checkpoint bertanda tangan yang disalin ke domain administrasi terpisah.

Hasil tersebut dapat membuat integritas dan jejak pembuktian dokumen internal sangat kuat dan mudah diuji. Karena persetujuan direktur tetap memakai OTP email, jaminan identitasnya dinilai **menengah**, bukan phishing-resistant. Tanpa PSrE, sistem **tidak boleh mengklaim sebagai TTE tersertifikasi** atau menyamakan tingkat pembuktian identitasnya dengan sertifikat elektronik PSrE. Nama pada UI dan dokumen adalah **“TTE Internal Terverifikasi—OTP”** dengan dimensi hasil yang eksplisit.

Keputusan final untuk agen:

- OTP tetap menjadi metode utama direktur; WebAuthn tidak termasuk scope wajib implementasi ini.
- OTP tidak pernah menjadi private key dan tidak dimasukkan ke evidence bundle.
- Segel public-key institusi membuktikan integritas paket bukti; OTP receipt membuktikan rangkaian persetujuan akun direktur.
- Level hasil dipisah: **integritas kriptografis tinggi**, **identitas OTP menengah**, **waktu sesuai jenis anchor yang tersedia**.
- Implementasi dilakukan bertahap dan setiap fase wajib lulus exit gate sebelum fase berikutnya.

## 2. Kondisi sistem saat ini

### 2.1 Kekuatan yang sudah ada

- Tindakan tanda tangan diperiksa terhadap posisi penandatangan dalam workflow.
- OTP dibuat untuk dokumen tertentu, memiliki masa berlaku, disimpan dalam bentuk hash, dan dinonaktifkan setelah dipakai.
- PDF final dirender dan disimpan sebagai artefak resmi.
- SHA-256 PDF final disimpan pada dokumen dan catatan tanda tangan.
- QR menggunakan token unik dan membuka halaman verifikasi publik.
- Halaman publik menghitung ulang hash file yang tersimpan di server dan membandingkannya dengan database.
- Metadata penandatangan, peran, delegasi, dan waktu disimpan.
- Aktivitas penting masuk ke audit log.
- Pengujian alur penuh sebelumnya membuktikan bahwa hash database sesuai dengan PDF final yang dihasilkan sistem.

### 2.2 Kesenjangan utama

| Area | Kondisi saat ini | Risiko |
|---|---|---|
| Integritas PDF | Hash dan PDF berada di lingkungan aplikasi yang sama | PDF dan hash dapat diganti bersama setelah kompromi server/DB |
| Identitas penandatangan | Password dan OTP email | OTP manual tidak tahan phishing; akun/email yang diambil alih dapat dipakai menandatangani |
| QR | Token melakukan lookup ke database | QR membuktikan apa yang dikatakan server saat ini, bukan bukti independen |
| Audit | Larangan perubahan hanya pada model ORM | SQL langsung/command dapat menghapus atau mengubah audit |
| Penyimpanan | File lokal dan dapat ditulis aplikasi | Artefak bisa ditimpa atau dihapus |
| Waktu | Waktu aplikasi/database | Dapat diperdebatkan jika host atau jam server dikompromikan |
| Bukti kriptografi | Belum ada tanda tangan public-key atas manifest/PDF | Hash saja tidak membuktikan siapa yang mengesahkan hash tersebut |
| Bukti offline | Belum tersedia | Verifikasi bergantung pada aplikasi dan database yang aktif |
| Snapshot konteks | Sebagian metadata tersimpan, belum menjadi manifest immutable | Perubahan nama, peran, workflow, atau delegasi dapat mengubah tafsir historis |
| Siklus kunci | Belum ada registry rotasi/revokasi | Tidak ada cara konsisten menjelaskan kunci lama, bocor, atau dicabut |
| Operasional | Terdapat command pembersihan transaksi yang dapat menghapus audit/signature/file | Bukti dapat hilang melalui operasi internal |
| Kredensial | Seeder memuat kredensial awal dalam teks sumber | Semua kredensial yang pernah terekspos harus dianggap berisiko |

Penilaian saat ini: **Internal Basic**. Target setelah seluruh fase: **Internal Strong—OTP (non-certified/non-PSrE)**. Istilah “high assurance” tidak dipakai untuk dimensi autentikasi personal selama masih menggunakan OTP email.

## 3. Batas klaim dan landasan

Pasal 11 UU ITE menetapkan syarat antara lain keterkaitan data pembuatan tanda tangan hanya kepada penandatangan, kontrol penandatangan atas data tersebut, keterdeteksian perubahan tanda tangan/informasi setelah penandatanganan, identifikasi penandatangan, dan adanya cara untuk menunjukkan persetujuan. Rancangan pada dokumen ini memetakan kontrol teknis ke unsur-unsur tersebut, tetapi kesimpulan keabsahan untuk kebijakan rumah sakit tetap perlu ditinjau penasihat hukum.

Peraturan Indonesia membedakan TTE tersertifikasi dan tidak tersertifikasi. Karena rencana ini sengaja tidak menggunakan PSrE, seluruh UI, laporan, SOP, dan komunikasi harus menyebutnya sebagai **TTE internal/tidak tersertifikasi** dan tidak memakai logo atau pernyataan yang dapat menimbulkan kesan sertifikasi PSrE.

Rujukan utama:

- [UU Nomor 11 Tahun 2008 tentang Informasi dan Transaksi Elektronik—JDIH Komdigi](https://jdih.komdigi.go.id/produk_hukum/view/id/167/t/undangundang%2Bnomor%2B11%2Btahun%2B)
- [UU Nomor 1 Tahun 2024—JDIH Komdigi](https://jdih.komdigi.go.id/produk_hukum/view/id/884/t/undangundang%20nomor%201%20tahun%202024)
- [PP Nomor 71 Tahun 2019—BPK RI](https://peraturan.bpk.go.id/Details/122030/Pp-No-71-Tahun-2019)
- [Permenkominfo Nomor 11 Tahun 2022—JDIH Komdigi](https://jdih.komdigi.go.id/produk_hukum/view/id/833/t/peraturan%20menteri%20komunikasi%20dan%20informatika%20nomor%2011%20tahun%202022)
- [NIST FIPS 186-5 Digital Signature Standard](https://csrc.nist.gov/pubs/fips/186-5/final)
- [NIST SP 800-63B Digital Identity Guidelines](https://pages.nist.gov/800-63-4/sp800-63b.html)
- [RFC 8785 JSON Canonicalization Scheme](https://www.rfc-editor.org/rfc/rfc8785.html)
- [RFC 7515 JSON Web Signature](https://www.rfc-editor.org/rfc/rfc7515.html)
- [RFC 3161 Time-Stamp Protocol](https://www.rfc-editor.org/rfc/rfc3161.html)
- [NIST SP 800-92 Guide to Computer Security Log Management](https://csrc.nist.gov/pubs/sp/800/92/final)

## 4. Model ancaman

Rancangan wajib diuji terhadap skenario berikut:

- Satu byte PDF diubah setelah ditandatangani.
- PDF dan nilai hash di database diganti bersama.
- File, database, serta halaman verifikasi utama dikuasai penyerang.
- Audit log dihapus, disisipkan, diubah, atau diurutkan ulang.
- Akun direktur atau email penerima OTP diambil alih.
- Administrator mengubah role, workflow, data delegasi, atau identitas setelah dokumen ditandatangani.
- Challenge penandatanganan lama diputar ulang pada dokumen atau versi berbeda.
- Penandatangan menyatakan tidak pernah melihat atau menyetujui PDF tersebut.
- Jam server dimundurkan untuk membuat bukti bertanggal palsu.
- Kunci pribadi institusi dicuri, akun direktur diambil alih, atau mailbox OTP dikompromikan.
- Administrator aplikasi mencoba menghapus atau menimpa bukti.
- Database utama hilang dan aplikasi tidak dapat diakses.
- Proses signing gagal di tengah jalan tetapi status dokumen sudah telanjur “ditandatangani”.
- Dua request penandatanganan berjalan bersamaan.
- Dokumen lama diverifikasi setelah kunci telah dirotasi atau dicabut.

Asumsi penting: tidak ada sistem yang mampu membuktikan identitas dunia nyata hanya dari hash dan server milik sendiri. OTP membuktikan bahwa kode yang dikirim ke kanal terdaftar dipakai dalam sesi yang benar; OTP sendirian tidak membuktikan bahwa manusia di depan perangkat pasti direktur. Karena itu verifikasi identitas akun, keamanan mailbox, pemisahan wewenang, notifikasi, dan prosedur pemulihan adalah bagian dari bukti.

## 5. Arsitektur bukti yang dituju

```text
PDF final (byte tetap)
       │ SHA-256
       ▼
Manifest bukti kanonis ──────► OTP receipt direktur
       │                              │
       ├──── signature institusi ◄────┘
       │
       ├──── PDF + bundle bukti ke WORM/Object Lock
       ├──── event ke audit hash-chain
       └──── checkpoint bertanda tangan ke penyimpanan terpisah

QR/unggah PDF/offline verifier
       │
       └──── memeriksa semua bukti secara independen, bukan hanya lookup token
```

Setiap lapisan memiliki fungsi berbeda:

- **Hash PDF**: membuktikan kesamaan byte.
- **OTP receipt direktur**: membuktikan OTP untuk transaksi tertentu dikirim ke kanal terdaftar dan berhasil dipakai oleh akun/sesi direktur.
- **Signature institusi**: membuktikan SIMPEL-RS mengesahkan paket bukti tersebut.
- **WORM**: mencegah artefak ditimpa/dihapus selama masa retensi.
- **Audit chain/checkpoint**: mendeteksi pengubahan sejarah peristiwa.
- **Timestamp/anchor independen**: memperkuat bukti bahwa artefak sudah ada pada suatu waktu.
- **Bundle offline**: menghilangkan ketergantungan pembuktian pada database dan website saat ini.

## 6. Komponen teknis yang direncanakan

### 6.1 Manifest bukti kanonis

Untuk setiap finalisasi, sistem membuat `evidence-manifest.json` yang deterministik dan tidak pernah diperbarui. Data minimum:

- `schema_version` dan `assurance_profile`.
- Evidence ID acak yang tidak dapat ditebak.
- UUID dokumen, ID internal, dan nomor versi.
- SHA-256, ukuran byte, dan MIME type PDF final.
- Nomor dokumen resmi, judul, klasifikasi, unit/instalasi, serta tanggal berlaku sebagai snapshot.
- ID dan hash snapshot template/workflow yang digunakan.
- Urutan persetujuan, keputusan, waktu, dan evidence ID setiap tahap.
- Snapshot penandatangan: ID internal immutable, nama, NIP/identitas organisasi bila tersedia, jabatan, unit, serta role signing.
- Snapshot delegasi: pemberi/penerima, dasar, ruang lingkup, mulai, berakhir, dan ID persetujuan delegasi.
- OTP challenge/receipt ID, kanal yang dimasking, policy version, jumlah percobaan, dan hasil—tanpa menyimpan kode OTP.
- Nonce/challenge unik, tujuan tindakan, batas waktu challenge, session binding, serta ID signing ceremony.
- Waktu UTC aplikasi, database, dan sumber waktu tambahan.
- Key ID institusi dan algoritma signature.
- ID event audit, hash event, dan checkpoint terkait.
- Lokasi/logical ID salinan WORM, retention-until, serta checksum setiap artefak.

Manifest dinormalisasi dengan **RFC 8785 JSON Canonicalization Scheme** sebelum di-hash dan ditandatangani. Semua implementasi PHP, CLI, dan verifier lain harus lulus test vector byte-identik. Float, timezone lokal, urutan properti yang tidak stabil, dan nilai opsional ambigu tidak boleh digunakan.

Versi manifest tidak boleh ditimpa. Perubahan skema menghasilkan versi baru dan verifier tetap mempertahankan dukungan untuk versi lama.

### 6.2 Signature public-key milik institusi

Manifest kanonis ditandatangani menggunakan algoritma standar, misalnya Ed25519 atau ECDSA P-256, melalui library matang. Pilihan final mengikuti dukungan KMS/HSM dan hasil threat assessment; jangan membuat algoritma kriptografi sendiri.

Ketentuan:

- Private key tidak berada dalam repository, `.env`, database aplikasi, backup database, atau filesystem web server.
- Gunakan HSM/KMS/Vault dengan operasi `sign`; aplikasi tidak pernah menerima private key mentah.
- App role hanya boleh meminta signature untuk payload yang sudah melewati state machine.
- Public key, fingerprint, masa berlaku, status, dan key ID dipublikasikan melalui beberapa kanal resmi yang independen dari aplikasi.
- Rotasi kunci tidak menghapus public key lama.
- Registry mencatat `active`, `retired`, `revoked`, waktu perubahan, alasan, dan pihak yang menyetujui.
- Revocation tidak otomatis membuat dokumen lama tidak valid; verifier menilai apakah signature dibuat sebelum peristiwa kompromi dan menampilkan status yang tepat.
- Penggunaan kunci, kegagalan, perubahan policy, dan rotasi masuk ke log KMS serta audit terpisah.
- Aktivasi, rotasi, recovery, dan pemusnahan key memerlukan dual control.

Makna bukti harus tepat: signature institusi membuktikan bahwa **institusi/SIMPEL-RS menyegel manifest**, bukan sendirian membuktikan bahwa direktur secara pribadi melakukan tindakan. Bukti persetujuan personal berasal dari akun direktur, reautentikasi, OTP transaction receipt, serta rangkaian audit. Nilainya tetap lebih rendah daripada authenticator phishing-resistant atau PSrE.

### 6.3 OTP direktur yang terikat transaksi

OTP email tetap menjadi metode persetujuan utama. Penguatan difokuskan agar kode tidak dapat dipakai untuk dokumen, versi, akun, atau sesi lain dan agar sistem menyimpan receipt yang kuat tanpa menyimpan kode OTP.

Upacara penandatanganan wajib:

1. Sistem memeriksa ulang bahwa akun aktif, memiliki role penandatangan yang tepat, workflow berada di tahap tanda tangan, dan delegasi masih berlaku.
2. Sistem mengunci versi dan merender **PDF final kandidat** terlebih dahulu.
3. Sistem menghitung SHA-256 PDF dan membuat draft manifest yang memuat nomor, judul, versi, unit, workflow, signer/delegation snapshot, dan hash PDF.
4. Direktur melihat preview final, nomor, judul, versi, tujuan tindakan, kanal OTP yang dimasking, serta fingerprint pendek hash. Tombol harus berbunyi jelas: “Kirim OTP untuk menandatangani dokumen ini”.
5. Sistem meminta reautentikasi password bila autentikasi terakhir lebih dari lima menit atau sesi mengalami perubahan risiko.
6. Server membuat challenge acak dengan CSPRNG dan mengikatnya pada `user_id + document_uuid + version_id + pdf_hash + manifest_draft_hash + session_id_hash + action + nonce + expiry`.
7. Server mengirim OTP **8 digit**, berlaku maksimum tiga menit, melalui email organisasi direktur yang sudah diverifikasi.
8. Email menyebut evidence/request ID pendek, judul/nomor yang aman ditampilkan, waktu kedaluwarsa, dan peringatan untuk tidak memberikan OTP kepada siapa pun. OTP tidak boleh muncul pada subject.
9. Direktur memasukkan OTP pada halaman yang sama. Server mengulang seluruh pemeriksaan authorization dan memastikan PDF/manifest hash tidak berubah.
10. Konsumsi OTP, perubahan state, dan pembuatan OTP receipt dilakukan atomik. Setelah berhasil, OTP tidak dapat dipakai ulang.
11. Manifest final memasukkan receipt, lalu disegel dengan signature public-key institusi dan disimpan ke WORM sebelum dokumen berstatus `sealed`.

Aturan penyimpanan dan validasi OTP:

- Gunakan `random_int()`/CSPRNG; jangan memakai generator pseudo-random biasa.
- Jangan simpan OTP plaintext. Karena ruang OTP pendek dapat di-brute-force jika hanya memakai hash biasa, simpan verifier berbasis HMAC-SHA-256 dengan secret pepper yang berada di KMS/secret manager terpisah dari database, ditambah challenge ID unik.
- Perbandingan menggunakan constant-time comparison.
- Maksimum lima percobaan per challenge; setelah itu challenge terkunci permanen.
- Maksimum tiga pengiriman per dokumen/user dalam 15 menit dan batas harian yang dapat dikonfigurasi. Route throttle hanya lapisan tambahan; batas persisten harus tetap bekerja lintas node/restart.
- Resend selalu mencabut semua OTP lama untuk dokumen/version/user yang sama.
- Perubahan PDF, versi, workflow, role, delegasi, email, password, atau session security state mencabut challenge aktif.
- OTP hanya boleh digunakan oleh akun, session lineage, document, version, action, dan manifest hash yang membentuk challenge.
- OTP, verifier, atau secret tidak boleh masuk URL, log, exception, telemetry, queue payload yang tidak terenkripsi, response produksi, atau evidence bundle.
- `debug_otp` hanya boleh tersedia dalam automated test; harus gagal tertutup pada `production`, `staging`, dan environment yang tidak dikenali.
- Queue email menyimpan challenge reference, bukan OTP lebih lama dari kebutuhan pengiriman. Retensi payload gagal harus dibatasi.
- Email tujuan tidak dapat diubah dari halaman signing. Perubahan email memakai proses terpisah, reautentikasi, maker-checker untuk akun direktur, notifikasi ke alamat lama dan baru, serta masa pending.

OTP receipt yang disimpan dan dimasukkan ke manifest:

- Challenge/receipt UUID, policy version, dan `method=otp_email_internal`.
- User immutable ID dan signer/delegation snapshot.
- Document UUID, version ID, PDF hash, manifest draft hash, action, dan nonce hash.
- Masked destination dan hash normalized destination menggunakan key terpisah; jangan tampilkan email penuh pada halaman publik.
- Requested, sent, expires, verified, consumed, dan sealed timestamps dalam UTC.
- Attempt count, resend generation, session ID hash, request/correlation ID, serta network/device metadata sesuai kebijakan privasi.
- Hasil pemeriksaan authorization, reauthentication age, dan alasan bila gagal.
- Tidak memuat kode OTP, verifier, pepper, atau data yang memungkinkan brute force offline.

Kontrol kompensasi karena OTP tidak tahan phishing:

- Notifikasi terpisah setelah tanda tangan berhasil, berisi nomor/evidence ID dan tautan pelaporan insiden.
- Alert anomali untuk device/IP baru, jam tidak biasa, resend, kegagalan berulang, perubahan mailbox, atau penandatanganan massal.
- Untuk klasifikasi dokumen sangat kritis, tambahkan maker-checker sebelum OTP direktur atau konfirmasi out-of-band oleh sekretariat; ini memperkuat proses tetapi tidak diklaim sebagai signature direktur kedua.
- Pembatalan karena akun/mailbox dikompromikan menghasilkan revocation event baru; bukti historis tidak dihapus.

WebAuthn/FIDO2 tetap dicatat sebagai peningkatan masa depan yang direkomendasikan, tetapi **bukan dependency, exit gate, atau scope wajib** rencana eksekusi ini.

### 6.4 Signature tertanam pada PDF

Fase lanjutan mengevaluasi CMS/PAdES menggunakan internal CA atau sertifikat institusi/per-direktur. Ini bukan PSrE dan viewer publik mungkin menampilkan certificate chain “untrusted” sampai root internal dipasang, tetapi signature tertanam tetap berguna untuk mendeteksi perubahan PDF secara kriptografis di luar SIMPEL-RS.

Ketentuan:

- Gunakan library CMS/PAdES yang mature dan diaudit; jangan mengimplementasikan manipulasi byte-range PDF sendiri.
- Signature mencakup PDF final, certificate chain internal, waktu, alasan, lokasi, dan referensi evidence ID.
- Tampilan tanda tangan visual hanyalah representasi; validitas berasal dari signature kriptografis.
- Bila signature PDF dan bundle berbeda hasilnya, verifikasi harus gagal keras dan memunculkan insiden.
- Penambahan tanda tangan berikutnya harus mempertahankan revision chain PDF dan tidak membatalkan signature sebelumnya.

### 6.5 Penyimpanan immutable/WORM

PDF final, manifest, signature institusi, OTP receipt, dan laporan verifikasi disimpan sebagai satu evidence bundle pada object storage dengan Object Lock/WORM.

Kontrol minimum:

- Bucket/account terpisah dari server dan database aplikasi.
- Versioning dan retention policy aktif sebelum produksi.
- App writer hanya memiliki `PutObject`; tidak memiliki overwrite, delete, retention bypass, atau perubahan policy.
- Akses baca dan pengelolaan retensi menggunakan identitas berbeda.
- Retention mengikuti jadwal retensi rekam/dokumen rumah sakit dan legal hold.
- Setelah upload, sistem membaca kembali objek dan memverifikasi hash, ukuran, version ID, serta retention-until.
- Metadata salinan tidak dipercaya sebagai satu-satunya hash; checksum isi tetap diverifikasi.
- Backup menerapkan prinsip 3-2-1, termasuk satu salinan offline/air-gapped atau administrative domain berbeda.
- Restore drill berkala membuktikan bahwa bundle dapat dipulihkan dan diverifikasi, bukan sekadar bahwa backup job “sukses”.

Jika object storage eksternal belum disetujui, tahap transisi dapat memakai media append-only/immutable backup yang dikendalikan unit infrastruktur terpisah. Ini harus dicatat sebagai kontrol sementara dengan risiko residu lebih tinggi.

### 6.6 Audit hash-chain dan checkpoint

Audit baru dibuat append-only secara desain, tidak hanya melalui larangan pada model ORM.

Setiap event memuat:

- sequence per stream/global;
- `previous_event_hash`;
- hash payload event kanonis;
- event type, actor, target, request/correlation ID;
- snapshot perubahan sebelum/sesudah untuk field penting;
- IP, user-agent, session/device ID yang dipseudonimkan sesuai kebijakan;
- waktu UTC aplikasi/database dan sumber waktu;
- hasil tindakan, alasan, serta policy version.

`event_hash = SHA-256(canonical_event_without_hash || previous_event_hash)`.

Penguatan operasional:

- Database account aplikasi tidak memiliki `UPDATE`/`DELETE` pada tabel evidence dan audit.
- Penulisan melalui account/procedure khusus yang hanya dapat append.
- Sequence dan unique constraint mencegah duplikasi serta fork yang tidak sah.
- Setiap jam/hari dibuat Merkle root atau checkpoint atas rentang event.
- Checkpoint ditandatangani kunci institusi dan disalin ke WORM/SIEM/account lain.
- Checkpoint dipublikasikan melalui sekurang-kurangnya satu kanal independen secara berkala agar sejarah tidak dapat ditulis ulang diam-diam.
- Verifier memeriksa continuity, missing sequence, chain hash, signature checkpoint, dan cakupan event dokumen.

Command pembersihan transaksi yang saat ini mampu menghapus audit, signature, dan file harus:

- dinonaktifkan secara permanen di production;
- dibatasi eksplisit hanya untuk `local`/`testing`;
- gagal tertutup bila environment ambigu;
- tidak pernah menghapus evidence production;
- memiliki typed confirmation, backup check, serta audit bila dipertahankan untuk test environment.

### 6.7 Bukti waktu

Waktu aplikasi saja bukan trusted timestamp. Rencana bertingkat:

1. Sinkronkan host dengan beberapa sumber NTP terpercaya, gunakan UTC, pantau drift, dan hentikan signing bila drift melewati batas.
2. Catat waktu dari aplikasi dan database serta selisihnya pada manifest.
3. Buat checkpoint bertanda tangan secara periodik ke domain terpisah.
4. Bila diizinkan, gunakan layanan timestamp RFC 3161 independen. Ini bukan integrasi tanda tangan PSrE, tetapi merupakan ketergantungan eksternal khusus untuk bukti keberadaan pada waktu tertentu.
5. Bila tidak boleh menggunakan layanan eksternal apa pun, publikasikan fingerprint checkpoint ke beberapa kanal institusi yang terpisah dan nyatakan dengan jujur bahwa assurance waktunya lebih rendah.

UI tidak boleh menyebut “waktu terpercaya” apabila hanya berasal dari jam aplikasi.

### 6.8 Verifikasi online, unggah PDF, dan offline

Halaman QR diubah dari lookup tunggal menjadi laporan pemeriksaan berlapis:

- Hash file yang diperiksa cocok dengan manifest.
- Signature institusi valid terhadap public key/key ID yang benar.
- OTP receipt valid, challenge terpakai tepat satu kali, dan terikat pada akun, sesi, document/version, PDF hash, serta manifest hash tersebut.
- Kanal OTP terverifikasi pada waktu signing dan status kompromi akun/mailbox ditampilkan bila ada.
- Signature PDF tertanam valid bila tersedia.
- Evidence bundle ada di WORM, checksum dan retensinya cocok.
- Audit chain dan checkpoint mencakup transaksi tanpa gap.
- Workflow, delegasi, serta signer snapshot konsisten.
- Status revocation, pembatalan dokumen, atau superseded ditampilkan terpisah dari integritas kriptografi.

Tambahkan **verifikasi dengan unggah PDF**:

- Pengguna dapat memilih PDF dari perangkatnya.
- Hash dihitung dan dicocokkan dengan evidence record.
- File unggahan tidak disimpan setelah pemeriksaan.
- Ukuran, MIME, parser isolation, rate limit, antivirus/sandbox, dan timeout diterapkan.
- Hasil menjelaskan setiap pemeriksaan, bukan hanya “ASLI/PALSU”.

Sediakan **evidence ZIP** yang dibuat saat signing:

- PDF resmi.
- Manifest JSON kanonis.
- Detached signature/JWS/CMS.
- OTP transaction receipt tanpa kode/verifier OTP.
- Public key/certificate chain yang diperlukan.
- Receipt/checkpoint audit.
- Laporan manusia (`verification-report.pdf/html`).
- README dan versi verifier yang sesuai.

Sediakan CLI/offline verifier dengan source dan checksum rilis yang dipublikasikan. Verifier tidak boleh memerlukan akses database untuk memeriksa integritas dasar. Ia harus memberikan exit code deterministik serta output JSON machine-readable untuk audit massal.

### 6.9 State machine penandatanganan yang atomik

Hindari keadaan ketika record final terlihat sah padahal file atau bukti belum selesai. State yang disarankan:

```text
draft/approved
    → preparing
    → awaiting_user_signature
    → user_signed
    → institution_sealed
    → stored_immutable
    → verified_after_write
    → sealed
```

Aturan:

- Status dokumen “ditandatangani/sealed” hanya diterbitkan setelah seluruh langkah wajib selesai.
- Jangan membuat record final dengan placeholder hash nol.
- File sementara tidak boleh tampil sebagai dokumen resmi.
- External storage/KMS tidak dipanggil sambil menahan transaksi database panjang.
- Gunakan outbox/job dengan idempotency key dan compare-and-swap state.
- Unique constraint mencegah dua final signature untuk versi dan role yang sama.
- Setiap challenge hanya sekali pakai, kedaluwarsa, dan terikat versi.
- Retry menghasilkan hasil yang sama; tidak membuat nomor, signature, atau bundle ganda.
- Kegagalan meninggalkan state non-final yang dapat direkonsiliasi dan diaudit.
- PDF/evidence `sealed` tidak boleh ditimpa; koreksi selalu menghasilkan versi baru yang berelasi dengan versi sebelumnya.

## 7. Identitas, akses, dan pemisahan wewenang

### 7.1 Tindakan keamanan segera

- Hapus kredensial plaintext dari seeder/source dan histori distribusi artefak bila relevan.
- Anggap seluruh kredensial yang pernah tercantum sebagai terekspos: rotasi semuanya dan wajibkan reset saat login.
- Jangan menaruh credential nyata pada `.env.example`, fixture, dokumentasi, screenshot, atau test log.
- Terapkan password panjang, pemeriksaan password bocor, rate limit adaptif, lockout aman, dan notifikasi login berisiko.
- Sediakan daftar sesi/perangkat serta pencabutan sesi.
- Wajibkan OTP transaksi untuk direktur. Untuk administrator dan pengelola key, gunakan MFA terpisah yang phishing-resistant bila infrastruktur memungkinkan; ini tidak mengubah metode TTE direktur.
- Reautentikasi khusus setiap tindakan signing; sesi login biasa tidak cukup.

### 7.2 Separation of duties

Tidak boleh ada satu akun yang sekaligus mampu:

- mengubah identitas/role direktur;
- mengubah email/kanal OTP direktur;
- mengubah workflow atau delegasi;
- mengakses private signing key;
- mengubah database bukti;
- menghapus object WORM;
- mengubah audit checkpoint;
- dan menyatakan hasil verifikasi valid.

Perubahan role, email/kanal OTP direktur, pemulihan akun, delegasi, rotasi key, retention policy, dan pembatalan dokumen memerlukan maker-checker/dual approval. Semua perubahan memiliki alasan, tiket, dan notifikasi ke pemilik identitas.

### 7.3 Validasi kanal OTP dan recovery akun

- Aktivasi akun direktur dan email organisasi diverifikasi tatap muka atau melalui prosedur kepegawaian yang setara.
- Petugas perubahan akun dan approver berbeda orang.
- Cocokkan identitas pegawai, jabatan aktif, kewenangan tanda tangan, email organisasi, dan nomor kontak notifikasi insiden.
- Perubahan email mencabut seluruh OTP/challenge aktif, sesi lama, dan memicu notifikasi ke alamat lama serta baru.
- Recovery akun direktur tidak boleh hanya melalui mailbox yang sama atau admin tunggal.
- Dugaan kompromi akun/mailbox segera mencabut sesi/challenge, membuka insiden, menonaktifkan signing sementara, dan menilai transaksi sejak waktu kompromi yang mungkin.
- Riwayat perubahan email disimpan sebagai audit immutable, tetapi alamat penuh tidak dipublikasikan.

## 8. Model data yang disarankan

Nama bersifat konseptual dan harus diselaraskan dengan skema aktual saat implementasi.

### `signing_keys`

- key ID, algorithm, purpose, provider/KMS reference.
- Public key/certificate, fingerprint.
- Activated/retired/revoked/compromised timestamps.
- Status, reason, policy version, approval references.

### `signature_otp_challenges`

- Challenge UUID, user ID, document ID, document version ID, dan signing ceremony ID.
- PDF hash, manifest draft hash, nonce hash, session ID hash, action, dan policy version.
- OTP verifier HMAC, masked destination, destination keyed-hash, dan resend generation.
- Attempt count/max attempts, requested/sent/expires/consumed/revoked timestamps.
- State: `pending_send`, `sent`, `consumed`, `expired`, `locked`, `revoked`, atau `send_failed`.
- Correlation/request ID, source metadata yang dibatasi, failure/revocation reason.
- Unique constraint agar hanya satu challenge aktif per user/document/version/action dan agar satu challenge hanya dapat dikonsumsi sekali.

### `signing_ceremonies`

- Ceremony UUID, document/version, intended actor/role.
- Manifest draft hash, challenge hash, nonce, expiry.
- State, reauthentication time, authorization/delegation result, dan OTP challenge/receipt ID.
- Created/consumed/failed timestamps dan failure reason.

### `signature_evidence`

- Evidence UUID dan schema/assurance version.
- Document/version dan immutable snapshots.
- PDF hash/size/object version.
- Canonical manifest bytes/hash.
- OTP transaction receipt fields tanpa OTP/verifier/pepper.
- Institution signature, algorithm, key ID.
- Timestamp/anchor references.
- Final state dan sealed time.

### `audit_chain_events`

- Stream/global sequence.
- Previous hash, canonical payload hash, event hash.
- Actor, target, type, request/correlation ID.
- Source times, result, metadata minimal.

### `audit_checkpoints`

- Sequence range, count, Merkle root/last chain hash.
- Signature, key ID, created time.
- WORM/external publication receipts.

### `evidence_storage_copies`

- Evidence ID, storage provider/bucket logical ID.
- Object key/version ID, checksum, size.
- Retention mode/until, write/read-back verification time.

Data historis tidak ditulis ulang. Signature lama diberi profil `legacy_internal_v1`; mekanisme baru memakai `internal_strong_otp_v2`. Verifier menampilkan level jaminan dan pemeriksaan yang tersedia untuk masing-masing versi.

## 9. UX bukti yang mudah dipahami

Halaman verifikasi harus menghindari satu badge hijau yang menutupi detail. Gunakan ringkasan berikut:

- **Integritas dokumen:** cocok/tidak cocok/tidak dapat diperiksa.
- **Persetujuan OTP direktur:** receipt valid/tidak valid/tidak tersedia; tampilkan bahwa assurance identitas berada pada level menengah.
- **Segel institusi:** valid/tidak valid/key dicabut.
- **Waktu:** anchored/internal-only/tidak konsisten.
- **Audit dan penyimpanan:** lengkap/gap/tidak tersedia.
- **Status administratif:** berlaku/dicabut/digantikan—status ini tidak mengubah fakta historis bahwa byte tertentu pernah ditandatangani.

Laporan harus menampilkan:

- Nama, jabatan, role, unit, dan dasar delegasi sebagai snapshot pada waktu signing.
- Nomor, judul, versi, SHA-256, evidence ID, dan key fingerprint.
- Waktu lokal WIB untuk manusia dan UTC untuk bukti mesin.
- Arti setiap status dan batas klaim non-PSrE.
- Tombol unduh evidence bundle dan panduan verifikasi offline.
- Riwayat supersede/revocation tanpa menghapus versi lama.

QR tetap dipakai untuk kemudahan akses, tetapi QR/image/paraf visual/watermark tidak disebut sebagai bukti kriptografis.

## 10. Tahapan implementasi

### P0 — Menutup risiko kritis

Estimasi target: 1–2 minggu, bergantung pada proses rotasi credential dan akses infrastruktur.

- Inventarisasi dan rotasi seluruh credential yang pernah tersimpan sebagai plaintext.
- Hapus credential nyata dari source/seeder; ganti dengan data dummy/test-only yang aman.
- Wajibkan reset password dan cabut seluruh sesi lama akun terdampak.
- Production-gate command pembersihan dan larang penghapusan bukti/audit.
- Koreksi state signing agar status final tidak terbit sebelum PDF dan hash selesai diverifikasi.
- Hilangkan placeholder final hash dan tambah idempotency/unique constraint.
- Pisahkan database role; cabut `UPDATE/DELETE` aplikasi dari tabel audit/evidence yang baru.
- Tambahkan upload-PDF hash verification sebagai peningkatan cepat, dengan kontrol upload aman.
- Labeli hasil lama secara jujur sebagai `Internal Basic`.
- Tambahkan alert untuk login, pengiriman/verifikasi/resend OTP, signing, perubahan email direktur, perubahan role/delegasi, dan perubahan konfigurasi workflow.

Exit gate:

- Tidak ada credential nyata di repository, image, log, dan fixture produksi.
- Tidak ada jalur aplikasi/command production yang dapat menghapus bukti final.
- Kegagalan render/storage tidak pernah menghasilkan status signed.
- Test concurrency, retry, rollback, dan upload-verification lulus.

### P1 — Manifest dan segel kriptografi institusi

Estimasi target: 2–4 minggu.

- Tetapkan skema manifest v2 dan canonicalization test vectors.
- Integrasikan KMS/HSM/Vault untuk operasi signature.
- Implementasikan key registry, rotasi, publikasi public key, dan revocation.
- Buat detached signed evidence bundle.
- Tingkatkan halaman verifikasi agar menguji signature, bukan hanya database hash.
- Buat offline CLI/verifier awal.
- Dokumentasikan key ceremony dan incident response.

Exit gate:

- Perubahan PDF atau manifest satu byte selalu gagal.
- Perubahan hash database tidak dapat menghasilkan signature valid.
- Bundle dapat diverifikasi pada mesin offline dari public key yang dipublikasikan.
- Kunci lama tetap dapat memverifikasi dokumen lama setelah rotasi.

### P2 — OTP direktur terikat transaksi

Estimasi target: 2–4 minggu.

- Buat tabel challenge/receipt terpisah; hentikan penyimpanan state OTP signing pada record user yang dapat tertimpa transaksi lain.
- Implementasikan signing ceremony yang mengikat OTP ke user, session, document/version, PDF hash, manifest hash, action, nonce, dan expiry.
- Terapkan OTP 8 digit, HMAC verifier dengan key di luar database, expiry tiga menit, maksimum lima percobaan, persistent rate limit, resend revocation, serta atomic consume.
- Wajibkan preview PDF final dan reautentikasi sebelum request OTP.
- Ulangi authorization, workflow, delegation, version, dan hash checks saat konsumsi OTP.
- Simpan receipt lengkap tanpa kode OTP dan hubungkan ke manifest/evidence.
- Hilangkan `debug_otp` dari seluruh environment selain automated testing dan pastikan OTP tidak masuk log/queue retention.
- Terapkan kontrol perubahan email, notifikasi selesai signing, anomaly alerts, dan incident revocation.

Exit gate:

- OTP dokumen A tidak dapat dipakai pada dokumen/versi/session/manifest B.
- Resend, perubahan dokumen, perubahan role/delegasi/email/password, logout, dan expiry mencabut challenge lama.
- Request konkuren hanya dapat mengonsumsi satu challenge tepat satu kali.
- Database challenge yang bocor tidak memungkinkan brute-force offline tanpa secret HMAC eksternal.
- Direktur melihat identitas dan fingerprint dokumen sebelum meminta serta memasukkan OTP.
- Evidence receipt membuktikan urutan request, send, verify, consume, dan seal tanpa mengungkap OTP.

### P3 — WORM, audit chain, checkpoint, dan waktu

Estimasi target: 3–6 minggu.

- Aktifkan object versioning dan Object Lock/WORM di domain terpisah.
- Terapkan read-after-write verification dan reconcile job.
- Implementasikan audit append-only hash-chain.
- Buat signed checkpoint dan replikasi ke storage/SIEM/domain lain.
- Sinkronisasi waktu, drift alert, dan fail-closed policy.
- Evaluasi RFC 3161 atau metode publikasi fingerprint independen.
- Terapkan backup 3-2-1 dan restore drill.

Exit gate:

- App role tidak dapat overwrite/delete evidence.
- Penghapusan, perubahan, insertion, atau reorder audit terdeteksi.
- Penguasaan database utama saja tidak cukup untuk memalsukan bundle lama.
- Restore dari backup menghasilkan pemeriksaan kriptografi identik.

### P4 — PDF signature dan assurance review

Estimasi target: 3–6 minggu.

- Pilih library CMS/PAdES dan desain internal CA/certificate lifecycle.
- Embed signature pada PDF tanpa merusak multi-signature revision chain.
- Buat verifier desktop/CLI dan paket dokumentasi pemeriksa eksternal.
- Lakukan penetration test independen, code review kriptografi, dan threat-model workshop.
- Lakukan review legal, kebijakan retensi, privasi, serta tata kelola bukti.
- Jalankan tabletop exercise untuk key compromise, insider attack, disaster recovery, dan sengketa tanda tangan.

Exit gate:

- PDF dapat menunjukkan perubahan melalui verifier standar/internal tanpa menghubungi SIMPEL-RS.
- Seluruh temuan kritis/high dari audit diselesaikan atau diterima secara formal dengan mitigasi.
- Tim operasional mampu mengekspor, memverifikasi, memulihkan, dan menjelaskan bukti.

## 11. Matriks prioritas

| Kontrol | Dampak bukti | Kompleksitas | Prioritas |
|---|---:|---:|---:|
| Rotasi credential plaintext dan forced reset | Sangat tinggi | Rendah–sedang | P0 |
| Production-gate penghapusan audit/evidence | Sangat tinggi | Rendah | P0 |
| State machine/idempotency signing | Sangat tinggi | Sedang | P0 |
| Manifest kanonis + signature institusi | Sangat tinggi | Sedang–tinggi | P1 |
| Offline evidence bundle/verifier | Tinggi | Sedang | P1 |
| OTP terikat hash/transaksi + HMAC verifier | Tinggi | Sedang | P2 |
| WebAuthn per signing transaction | Sangat tinggi untuk identitas | Tinggi | Masa depan/opsional |
| WORM/Object Lock terpisah | Sangat tinggi | Sedang–tinggi | P3 |
| Audit hash-chain + signed checkpoint | Sangat tinggi | Tinggi | P3 |
| RFC 3161/external checkpoint anchor | Tinggi | Sedang | P3 |
| CMS/PAdES internal certificate | Tinggi | Tinggi | P4 |
| Watermark/QR visual tambahan | Rendah untuk kriptografi | Rendah | Pelengkap |
| Blockchain tanpa identity/key governance | Rendah–sedang | Tinggi | Tidak diprioritaskan |

## 12. Rencana pengujian dan bukti kelulusan

### 12.1 Integritas dan kriptografi

- Ubah satu byte PDF: hash, manifest, dan PDF signature harus gagal.
- Ubah hash di database: signature manifest harus gagal.
- Ganti PDF dan hash database bersama: bundle/WORM/checkpoint tetap mengungkap perubahan.
- Ubah salah satu field manifest atau urutan serialisasi: canonicalization/signature diuji deterministik.
- Jalankan test vector yang sama pada PHP dan offline verifier; hasil byte/hash harus identik.
- Gunakan key yang tidak dikenal, retired, revoked, atau algoritma tidak diizinkan; status harus tepat.
- Uji rotasi key dan verifikasi dokumen sebelum/sesudah rotasi.

### 12.2 OTP dan autentikasi

- Replay OTP/challenge ditolak setelah konsumsi pertama.
- OTP dokumen A pada dokumen/version/manifest/session B ditolak.
- OTP lama setelah resend, perubahan PDF, workflow, delegation, role, email, password, logout, atau expiry ditolak.
- OTP salah lima kali mengunci challenge tanpa dapat dibuka dengan resend terhadap generation yang sama.
- Rate limit persisten bekerja lintas worker, node, restart, dan pergantian session.
- Kode tidak pernah ditemukan pada database dump, log, exception, queue gagal, response produksi, atau bundle bukti.
- Manipulasi attempt count/state secara konkuren tidak menghasilkan double consume.
- HMAC verifier tidak dapat divalidasi tanpa secret di KMS/secret manager.
- Dua signing request konkuren hanya menghasilkan satu final evidence.
- Perubahan role/delegasi setelah signing tidak mengubah snapshot bukti lama.
- Perubahan/recovery email direktur membutuhkan maker-checker, mencabut sesi/challenge lama, dan menimbulkan notifikasi.
- Reautentikasi lebih tua dari batas policy ditolak sebelum OTP diterbitkan.

### 12.3 Audit dan storage

- Hapus, sisipkan, edit, atau reorder event; chain/checkpoint verification harus gagal.
- Coba overwrite/delete object dengan app credential; storage harus menolak.
- Ubah retention policy; butuh role terpisah dan dual control.
- Putuskan WORM/KMS sementara; signing tidak boleh menjadi `sealed` dan retry tetap idempotent.
- Restore backup pada lingkungan bersih dan verifikasi semua bundle tanpa database production.
- Simulasikan hilangnya database utama; offline bundle tetap memverifikasi integritas dan signature.

### 12.4 Workflow dan kegagalan proses

- Render PDF gagal, KMS timeout, storage timeout, read-after-write mismatch, job retry, dan database deadlock.
- Dokumen dibatalkan atau workflow berubah di tengah ceremony.
- Delegasi kedaluwarsa tepat sebelum konsumsi challenge.
- User dinonaktifkan atau role dicabut di antara challenge dan signature.
- Nomor dokumen collision dan request ganda.
- Rekonsiliasi mendeteksi orphan temp file, orphan object, atau state menggantung tanpa mempromosikannya menjadi final.

### 12.5 Keamanan verifier

- File terlalu besar, MIME palsu, malformed PDF, decompression bomb, script/embedded payload, dan request flood.
- Token QR acak/brute force tidak membocorkan data sensitif.
- Verifier tidak mengeksekusi konten PDF dan tidak menyimpan upload.
- Error response tidak membocorkan lokasi storage, SQL, key reference, atau detail internal.
- Laporan lama tetap dapat dibuka tanpa mengandalkan CDN/library yang hilang.

### 12.6 Uji manusia dan sengketa

- Auditor non-teknis dapat menjawab siapa, dokumen apa, kapan, metode apa, dan hasil cek apa dari satu laporan.
- Auditor teknis dapat memverifikasi bundle offline mengikuti README.
- Simulasikan penyangkalan direktur, key hilang, server diretas, dan dokumen diganti.
- Catat artefak apa yang mendukung atau melemahkan setiap klaim; jangan menyembunyikan hasil “unknown”.

## 13. Monitoring dan respons insiden

Alert real-time untuk:

- signing di luar jam/pola normal;
- beberapa kegagalan, resend, lockout, atau request OTP lintas device/IP;
- perubahan/recovery email dan akun direktur;
- perubahan role, jabatan, delegasi, workflow, atau policy;
- key operation anomali, rotasi, dan perubahan KMS policy;
- clock drift;
- audit chain gap/checkpoint gagal;
- WORM write/read-back mismatch;
- percobaan delete/overwrite evidence;
- lonjakan lookup token atau upload verifier.

Runbook minimum:

- Dugaan kompromi akun atau mailbox OTP direktur.
- Dugaan kompromi private key institusi.
- File/hash/checkpoint mismatch.
- Kerusakan atau kehilangan storage/database.
- Salah tanda tangan atau pencabutan administratif.
- Permintaan ekspor bukti untuk audit/sengketa.

Insiden tidak boleh “diperbaiki” dengan mengubah bukti lama. Buat event koreksi/revocation/supersede baru yang menunjuk artefak lama.

## 14. Kebijakan organisasi yang wajib mendampingi kode

- SK/kebijakan definisi TTE internal, level assurance, dan batas penggunaannya.
- Matriks kewenangan penandatangan per jenis dokumen.
- SOP aktivasi akun/email direktur, perubahan kanal, recovery, incident revocation, dan offboarding.
- SOP delegasi: dasar, cakupan, waktu berlaku, persetujuan, dan larangan delegasi berantai bila tidak sah.
- SOP key ceremony, rotasi, backup, compromise, dan destruction.
- Retention schedule, legal hold, backup, restore, serta pemusnahan setelah retensi.
- SOP OTP, resend, lockout, perubahan email, serta maker-checker untuk dokumen sangat kritis.
- SOP verifikasi pihak ketiga serta ekspor evidence bundle.
- Privacy notice untuk IP/device metadata dan pembatasan aksesnya.
- Review akses berkala dan rekonsiliasi akun pegawai/jabatan.
- Audit berkala serta penetration test independen.
- Pelatihan direktur/verifikator untuk membaca preview, mendeteksi phishing, dan melaporkan kehilangan key.

## 15. Deliverables implementasi

- Threat model dan data-flow diagram yang disetujui.
- Architecture Decision Record untuk canonicalization, algoritma, KMS, WORM, OTP transaction binding, dan PDF signing.
- Migrasi skema v2 dan strategi dual-read v1/v2.
- Service manifest, institutional signing, OTP ceremony, WORM writer, audit chain, dan verifier.
- UI preview/reauthentication/request/consume OTP dan administrasi perubahan email direktur.
- Halaman QR berlapis serta upload-PDF verifier.
- Evidence bundle generator dan offline CLI/verifier.
- Public key registry/revocation endpoint dan kanal publikasi fingerprint.
- Monitoring dashboard, alerts, reconciliation jobs, dan runbook.
- Key management/OTP/delegation/account-recovery/incident/DR SOP.
- Test suite unit, integration, E2E, concurrency, fault injection, security, dan restore.
- Laporan review hukum, security assessment, penetration test, serta risk acceptance.

## 16. Definition of Done

Program baru dianggap selesai bila:

- PDF, manifest, OTP receipt, signature institusi, audit receipt, dan storage receipt terhubung dengan ID/hash yang konsisten.
- Pihak ketiga dapat memverifikasi evidence bundle tanpa akses database dan tanpa mempercayai jawaban website semata.
- Bukti persetujuan berasal dari akun, reautentikasi, OTP challenge terikat transaksi, dan receipt yang dapat diaudit; UI menyatakan assurance identitasnya menengah.
- Private key institusi tidak pernah tersedia bagi aplikasi sebagai material mentah.
- App/database administrator tunggal tidak mampu memalsukan atau menghapus seluruh lapisan bukti tanpa meninggalkan deteksi.
- Evidence final tidak dapat ditimpa atau dihapus selama retensi.
- Gangguan parsial tidak pernah menerbitkan status `sealed` palsu.
- Key rotation, revocation, supersede, dan dokumen legacy diverifikasi dengan semantik yang benar.
- Restore drill serta skenario sengketa berhasil dijalankan dan terdokumentasi.
- Semua test pada Bagian 12 lulus dan temuan kritis/high independen ditutup.
- UI dan laporan selalu menyatakan level assurance serta batas non-PSrE secara akurat.
- Kebijakan, SOP, owner, jadwal audit, dan anggaran operasional telah disahkan—bukan hanya kode telah di-deploy.

## 17. Hal yang tidak boleh dianggap sebagai solusi tunggal

- **QR code:** hanya alamat/evidence locator; dapat disalin.
- **Gambar tanda tangan/stempel/watermark:** mudah disalin dan tidak menjamin integritas byte.
- **Hash di database:** lemah bila file dan hash dapat diubah oleh pihak yang sama.
- **OTP email:** dalam rencana ini adalah metode utama direktur dan diperkuat dengan transaction binding, tetapi tetap tidak tahan phishing/takeover email dan tidak boleh disebut setara WebAuthn atau PSrE.
- **Blockchain:** timestamp/hash publik tidak otomatis membuktikan identitas orang yang menyetujui atau keamanan enrolment.
- **Internal CA:** memberi verifikasi kriptografis, tetapi tidak otomatis dipercaya publik dan bukan sertifikasi PSrE.
- **Audit ORM immutable:** dapat dilewati dengan SQL, akun database, command, backup restore, atau akses administrator.
- **HTTPS:** melindungi koneksi saat digunakan, bukan membuktikan sejarah dokumen setelah server dikompromikan.

## 18. Keputusan final dan asumsi eksekusi

Keputusan berikut tidak boleh ditafsirkan ulang oleh agen tanpa instruksi pengguna baru:

| Area | Keputusan eksekusi |
|---|---|
| Metode direktur | OTP email organisasi sebagai metode utama |
| Profil | `internal_strong_otp_v2`; legacy tetap `legacy_internal_v1` |
| OTP | 8 digit, TTL 3 menit, maksimum 5 percobaan, maksimum 3 kirim/15 menit/user/dokumen |
| Binding | User, session lineage, document, version, PDF hash, manifest hash, action, nonce, expiry |
| Verifier OTP | HMAC-SHA-256 dengan secret di KMS/secret manager; tidak memakai hash pendek tanpa secret |
| File hash | SHA-256 atas byte PDF final |
| Manifest | JSON kanonis RFC 8785, versioned dan immutable |
| Segel institusi | Interface public-key signer; Ed25519 menjadi pilihan default bila provider mendukung |
| Penyimpanan | Interface immutable evidence store; local fake hanya untuk test/dev, WORM wajib sebelum profile v2 di production |
| Waktu | UTC + application/database time + signed checkpoint; RFC 3161 adapter opsional sampai disetujui |
| Verifikasi | QR lookup, upload PDF, dan offline bundle; hasil per dimensi |
| Dokumen lama | Tidak diubah dan tidak ditandatangani ulang; diberi label legacy |
| WebAuthn | Di luar scope wajib; hanya future enhancement |
| PSrE | Tidak digunakan dan tidak diklaim |

Keputusan yang memang memerlukan pemilik organisasi/infrastruktur tidak boleh diisi dengan tebakan agen:

- Masa retensi dan aturan legal hold.
- Provider KMS/HSM/Vault serta identity/permission production.
- Provider WORM/Object Lock dan administrative domain salinan kedua.
- Kanal publikasi public key/checkpoint resmi.
- Izin memakai RFC 3161, internal CA, atau CMS/PAdES.
- Maker/checker bernama dan penanggung jawab legal, security, infrastruktur, audit, serta proses dokumen.

Agen tetap dapat menyelesaikan schema, interface, domain logic, UI, verifier, dan automated tests menggunakan adapter fake/in-memory khusus test. Agen **tidak boleh** membuat private key production, secret production, bucket production, mengisi credential nyata, atau mengaktifkan profile v2 production sebelum keputusan infrastruktur disediakan.

## 19. Kontrak eksekusi untuk agen

### 19.1 Aturan kerja

1. Baca dokumen ini seluruhnya, lalu audit implementasi aktual sebelum mengubah file.
2. Periksa `git status`; pertahankan seluruh perubahan pengguna yang tidak terkait.
3. Buat perubahan additive dan kompatibel dengan data lama. Jangan drop kolom OTP lama sampai dual-read/migrasi serta masa transisi selesai.
4. Jangan menjalankan command destruktif, menghapus dokumen/signature/audit, atau membersihkan database pengguna.
5. Jangan mengubah `.env` atau memasukkan secret. `.env.example` hanya boleh mendokumentasikan **nama variabel dan placeholder kosong**, tidak pernah nilai nyata; aplikasi production mengambil secret dari secret manager/runtime injection.
6. Jangan membuat kriptografi sendiri. Bungkus provider melalui interface, gunakan primitive/library terawat, dan tambah known-answer tests.
7. Setiap fase berakhir dengan test terarah, full relevant suite, migration rollback test pada database test, `git diff --check`, dan laporan file yang berubah.
8. Jangan melanjutkan ke fase berikutnya bila exit gate fase aktif gagal.
9. Jangan menyebut implementasi selesai jika adapter production KMS/WORM belum tersedia; laporkan dengan tepat bagian aplikasi yang selesai dan gate deployment yang masih terbuka.

### 19.2 Titik kode awal yang wajib diaudit

- `app/Models/User.php`: pindahkan tanggung jawab `generateOtp()`/validasi OTP signing dari record user ke challenge service/table baru.
- `app/Http/Controllers/TandaTanganController.php`: request OTP, validasi input, reautentikasi, throttling, dan penghapusan `debug_otp` di luar automated test.
- `app/Services/DocumentService.php`: authorization ulang, state machine, PDF hash, manifest, atomic OTP consume, seal, WORM write, dan final state.
- `app/Notifications/OtpTandaTangan.php`: template email transaksi, masking, expiry, dan larangan kebocoran OTP ke subject/log.
- `app/Models/DocumentSignature.php` serta migration signature: relasi evidence/profile/receipt dan kompatibilitas legacy.
- `app/Models/AuditLog.php`: jangan mengandalkan immutable di ORM; arahkan event v2 ke append-only chain.
- `app/Console/Commands/CleanTransactionsCommand.php`: production guard yang fail-closed dan tidak dapat menghapus evidence production.
- `app/Http/Controllers/PublicVerifyController.php`, `resources/views/public/verify.blade.php`, dan `routes/web.php`: pemeriksaan berlapis serta endpoint upload PDF aman.
- `resources/views/tanda-tangan/show.blade.php`: preview final, hash fingerprint, reauthentication state, request/resend/consume UX, dan peringatan OTP.
- `config/app.php`, `config/session.php`, dan `config/queue.php`: policy configuration tanpa secret value.
- `tests/Feature/WorkflowSecurityTest.php`, `tests/Feature/DocumentEndToEndWorkflowTest.php`, dan test baru khusus OTP/evidence/verifier.

Daftar ini adalah titik awal, bukan izin untuk mengubah semua file. Agen harus memakai pencarian referensi sebelum refactor agar pemanggil lama dan test existing tidak rusak.

### 19.3 Urutan implementasi wajib

#### Paket A — Baseline dan regression lock

- Jalankan test yang ada dan catat baseline failure sebelum perubahan.
- Tambah characterization tests untuk OTP saat ini, authorization signer/delegation, E2E signing, PDF hash, public verify, numbering collision, dan command production guard.
- Pastikan test tidak bergantung pada OTP yang bocor melalui response production; helper test boleh memperoleh OTP hanya dari fake notifier/challenge test helper.

Gate A: baseline dipahami, test baru mampu gagal pada celah yang hendak diperbaiki, dan tidak ada perubahan behavior production yang belum disengaja.

#### Paket B — Challenge OTP v2

- Tambah migration/model `signature_otp_challenges` dengan state, binding, indexes, unique constraints, timestamps, dan receipt fields pada Bagian 8.
- Buat `SigningOtpService` dengan operasi minimal `request`, `markSent`, `verifyAndConsume`, `revokeActive`, dan `expire`.
- Buat `OtpVerifier`/secret-provider interface. Adapter test deterministik hanya aktif di environment testing; adapter production fail-closed bila secret provider tidak tersedia.
- Ubah notification/controller menggunakan challenge service, bukan field OTP pada user.
- Terapkan persistent throttling, resend generation, maximum attempts, constant-time comparison, dan transaction/row lock saat consume.
- Pertahankan pembacaan legacy hanya bila masih dibutuhkan dokumen lama; jangan menerbitkan challenge legacy baru.

Gate B: seluruh test Bagian 12.2 lulus; OTP tidak muncul pada log, database plaintext, response non-test, atau failed job payload yang dipertahankan.

#### Paket C — Final PDF, manifest, dan state machine

- Tambah migration/model `signing_ceremonies` dan `signature_evidence`.
- Pecah rendering kandidat, hashing, manifest draft, OTP request, OTP consume, institutional seal, immutable write, read-back verify, dan publish final state.
- Buat canonical manifest serializer beserta RFC 8785/known-answer/cross-run deterministic tests.
- Pastikan OTP dibuat setelah hash final kandidat tersedia dan otomatis dicabut bila byte/konteks berubah.
- Hilangkan placeholder final hash. Gunakan outbox/idempotency dan constraint untuk mencegah double signing.
- Simpan snapshot workflow, signer, role, unit, dan delegation pada evidence, bukan membaca data mutable saat verifikasi sejarah.

Gate C: failure injection pada setiap transisi tidak pernah menghasilkan dokumen `sealed` parsial; concurrency hanya menghasilkan satu evidence final.

#### Paket D — Segel institusi dan evidence bundle

- Buat `EvidenceSigner` dan `SigningKeyRegistry` interfaces.
- Implementasikan fake signer hanya untuk test. Implementasi production harus memakai provider KMS/HSM/Vault dan fail-closed jika belum dikonfigurasi.
- Tandatangani hash/bytes manifest kanonis dan simpan algorithm, key ID, public key fingerprint, serta signature.
- Hasilkan evidence ZIP deterministik berisi artefak pada Bagian 6.8 tanpa OTP/verifier/secret.
- Buat verifier service dan CLI yang dapat memeriksa bundle tanpa database.

Gate D: perubahan satu byte pada PDF, manifest, receipt, atau signature terdeteksi; bundle test dapat diverifikasi offline.

#### Paket E — Verifikasi file yang dipegang pengguna

- Ubah halaman QR agar menjelaskan bahwa QR saja hanya mengidentifikasi record.
- Tambah endpoint upload-PDF yang menghitung hash file pengguna dan membandingkannya dengan manifest bertanda tangan.
- Jangan menyimpan upload; terapkan batas ukuran, MIME/magic bytes, rate limit, temporary isolation, cleanup, dan error generik.
- Tampilkan hasil per dimensi: file, OTP receipt, segel institusi, waktu, audit/storage, dan status administratif.
- Jangan tampilkan “ASLI” jika file pengguna belum diperiksa. Gunakan “record resmi ditemukan; file belum dibandingkan”.

Gate E: PDF asli lulus, perubahan satu byte gagal, salinan QR pada PDF modifikasi tidak menghasilkan klaim file-valid, dan file berbahaya/berlebih ditolak aman.

#### Paket F — Audit chain dan immutable storage

- Tambah `audit_chain_events`, `audit_checkpoints`, serta `evidence_storage_copies` secara additive.
- Implementasikan append-only writer, chain verifier, checkpoint signer, reconciliation, dan alert gap.
- Buat `ImmutableEvidenceStore` interface. Adapter local hanya untuk test/dev; profile v2 production fail-closed tanpa WORM provider dan retention policy.
- Terapkan database permissions production melalui runbook/migration khusus yang direview untuk SQL Server; jangan mengasumsikan hak DBA dari aplikasi.
- Kunci `CleanTransactionsCommand` ke local/testing dan tambahkan automated test environment guard.

Gate F: mutation/reorder/delete audit terdeteksi; app credential tidak dapat overwrite evidence; restore/reconcile test lulus.

#### Paket G — Hardening, dokumentasi, dan handoff

- Rotasi credential yang terekspos dilakukan oleh operator berwenang; agen hanya menghapus nilai nyata dari source dan menambah guard/test, tidak mencetak credential.
- Tambah monitoring hooks, structured redaction, incident/revocation flow, key/email change audit, dan runbook.
- Jalankan seluruh test suite, static/lint checks yang tersedia, migration fresh/rollback pada database test, dan pemeriksaan route/config cache.
- Review threat model terhadap implementasi aktual dan perbarui dokumentasi bila nama class/schema berubah tanpa mengurangi kontrol.
- Hasil akhir memuat daftar migration, konfigurasi placeholder, kebutuhan KMS/WORM, test result, risiko residu OTP, dan deployment/rollback checklist.

Gate G: Definition of Done Bagian 16 terpenuhi untuk adapter aplikasi; deployment production tetap diblokir sampai KMS, WORM, retensi, public key publication, dan owner organisasi disahkan.

### 19.4 Larangan implementasi

- Jangan menyimpan private key atau OTP pepper di source, `.env.example`, database, bundle, atau filesystem publik.
- Jangan memakai `APP_KEY` yang sama untuk OTP HMAC dan fungsi enkripsi aplikasi lain.
- Jangan menaruh OTP plaintext kembali ke kolom `users.otp_code`.
- Jangan mengirim OTP sebelum PDF hash dan manifest draft terkunci.
- Jangan menerima OTP hanya berdasarkan `user_id + document_id`; seluruh binding v2 wajib diperiksa.
- Jangan menandai dokumen signed sebelum seal dan immutable write terverifikasi.
- Jangan menjadikan token QR sebagai bukti keaslian file yang sedang dipegang pengguna.
- Jangan menghapus atau menulis ulang signature/audit legacy.
- Jangan mengklaim OTP sebagai phishing-resistant, non-repudiation absolut, TTE tersertifikasi, atau setara PSrE.

# PANDUAN STANDAR PENGATURAN TINGKAT VERIFIKASI & WORKFLOW
## SISTEM INFORMASI MANAJEMEN PERSURATAN ELEKTRONIK RUMAH SAKIT (SIMPEL-RS)

---

## 1. PENDAHULUAN & STRUKTUR ORGANISASI RUMAH SAKIT

SIMPEL-RS dirancang untuk memastikan tata kelola tata naskah dinas rumah sakit berjalan tertib, cepat, akuntabel, dan memenuhi standar akreditasi rumah sakit (KARS) serta regulasi perundang-undangan (UU ITE).

Struktur tata persuratan di lingkungan Rumah Sakit disusun berdasarkan unit tata kelola fungsional dan struktural:
1. **Unit**: Satuan kerja operasional pelaksana tugas administratif dan teknis (misal: Unit SDM & Diklat, Unit Umum, Unit Perbendaharaan).
2. **Instalasi**: Fasilitas pelayanan medis dan penunjang medis (misal: Instalasi Rawat Jalan, Instalasi Rawat Inap, Instalasi Gawat Darurat, Instalasi Farmasi, Instalasi Laboratorium).
3. **Tim**: Kelompok kerja fungsional yang dibentuk untuk menangani program kerja khusus rumah sakit (misal: Tim K3RS, Tim Pengendali Resistensi Antimikroba/PPRA).
4. **Komite**: Badan penasihat dan pengendali standar profesi dan mutu rumah sakit (misal: Komite Medik, Komite Keperawatan, Komite Tenaga Kesehatan Lain, Komite Mutu & Keselamatan Pasien/PMKP, Komite Etik & Hukum).
5. **Manajemen / Direksi**: Pimpinan tertinggi rumah sakit yang memegang kewenangan pengesahan akhir (Tanda Tangan Elektronik / TTE).

---

## 2. METODE ALUR VERIFIKASI (WORKFLOW)

SIMPEL-RS mendukung 2 (dua) skema verifikasi fleksibel yang dapat disesuaikan per jenis naskah dinas:

```
┌─────────────────────────────────────────────────────────────┐
│                       SKEMA VERIFIKASI                      │
├──────────────────────────────┬──────────────────────────────┤
│ 1. Mode Serial (Berjenjang)  │ 2. Mode Parallel (Quorum)    │
│    Level 1 ──► Level 2 ──► TTE │    [Pool: A, B, C]           │
│    (Berurutan satu per satu) │    (Min. 2 Setuju ──► Lolos) │
└──────────────────────────────┴──────────────────────────────┘
```

### A. Mode Serial (Tunggal / Berjenjang)
* **Konsep**: Dokumen diverifikasi secara berurutan tingkat demi tingkat. Dokumen baru masuk ke antrian verifikator tingkat berikutnya setelah disetujui oleh verifikator pada tingkat saat ini.
* **Penggunaan**: Naskah dinas standar seperti Nota Dinas, Surat Tugas, atau Laporan Internal.
* **Penetapan Verifikator**:
  * Ditentukan otomatis berdasarkan **Role/Jabatan** pada sistem, atau
  * Dipilih langsung oleh Pengusul saat mengajukan draft.

### B. Mode Parallel (Multi-Approval Quorum)
* **Konsep**: Dokumen dikirimkan secara serentak ke sekelompok verifikator (*Pool Verifikator*). Dokumen dinyatakan lolos tahapan tersebut jika jumlah persetujuan telah mencapai batas minimum (*Quorum*) yang ditetapkan.
* **Penggunaan**: Naskah yang membutuhkan persetujuan lintas fungsi/komite (misal: Pedoman Pelayanan yang melibatkan Komite Medik, Komite PMKP, dan Komite Keperawatan).
* **Aturan Khusus**:
  * **Quorum (Minimal Persetujuan)**: Jumlah orang yang wajib menyetujui (misal: Minimal `2` persetujuan dari `4` anggota pool).
  * **Prinsip Kegagalan**: Apabila salah satu verifikator dalam pool memberikan catatan penolakan/revisi, dokumen langsung ditahan untuk perbaikan.

---

## 3. MEKANISME PENGEMBALIAN & PENOLAKAN NASKAH (AUDIT TRAIL)

Sistem menjamin prinsip kehati-hatian (*duty of care*) dan integritas audit dengan alur penolakan formal:

```
┌─────────────────────────────────────────────────────────────┐
│                 ALUR PENOLAKAN & EVALUASI                   │
│                                                             │
│  [Penandatangan / TTE]                                      │
│         │ (Tolak & Beri Catatan)                            │
│         ▼                                                   │
│  [Verifikator Tingkat Tertinggi]                            │
│         │                                                   │
│         ├─► (Perbaiki & Ajukan Kembali ke TTE)              │
│         └─► (Kembalikan 1 Level ke Bawah / ke Pengusul)     │
└─────────────────────────────────────────────────────────────┘
```

1. **Penolakan oleh Penandatangan (TTE)**:
   * Penandatangan dapat menolak draft naskah dengan mengisi **Catatan Penolakan** (wajib diisi).
   * Status dokumen berubah menjadi `Ditolak Penandatangan`.
   * Sistem secara otomatis me-reset status verifikasi tingkat tertinggi menjadi `Menunggu`, sehingga verifikator terkait dapat langsung meninjau kembali catatan perbaikan.
2. **Pengembalian oleh Verifikator (Turunkan ke Level Bawah)**:
   * Verifikator yang menerima pengembalian dapat memperbaiki dokumen langsung melalui web editor atau menurunkannya 1 tingkat ke verifikator di bawahnya / ke pengusul.
   * Catatan pengembalian tercatat lengkap pada **Riwayat Verifikasi & Timeline Status**.

---

## 4. PANDUAN PENGATURAN PADA PANEL ADMINISTRATOR

Langkah-langkah bagi Administrator untuk mengatur alur verifikasi naskah:

### Langkah 1: Akses Master Template Workflow
1. Buka menu **Admin** pada sidebar kiri.
2. Pilih submenu **Template Workflow** (URL: `/admin/workflows`).
3. Pada tabel template, Anda dapat melihat daftar template yang terhubung dengan masing-masing Jenis Naskah Dinas.

### Langkah 2: Menambah atau Mengedit Template
1. Klik **"+ Tambah Template Workflow"**.
2. Masukkan nama template (contoh: *Workflow Standar SPO Pelayanan*).
3. Pilih **Jenis Naskah** yang relevan (contoh: *Standar Prosedur Operasional*).
4. Tentukan cakupan Unit Kerja (pilih *Semua Unit* untuk berlaku global).
5. Centang **"Jadikan Template Utama"** dan klik **"Simpan Workflow"**.

### Langkah 3: Mengatur Tingkatan Tahap (Steps) & Quorum
1. Pada baris template yang telah dibuat, klik tombol **"Kelola Tahapan"**.
2. Klik tombol **"+ Tambah Tahapan"** untuk membuat tingkatan verifikasi:
   * **Nama Tahapan**: Gunakan istilah baku (contoh: *Verifikasi Kepala Instalasi*, *Review Komite Mutu*, *Pengesahan Direktur Utama*).
   * **Urutan**: Tentukan urutan tahapan (1, 2, 3, dst.).
   * **Tipe**: Pilih `Verifikasi` untuk penelaah atau `Penandatangan` untuk pejabat penandatangan akhir.
   * **SLA (Hari)**: Masukkan batas waktu kerja penyelesaian (default: 2 hari kerja).
3. **Pengaturan Mode Verifikasi**:
   * **Jika Mode Serial**: Pilih Role spesifik yang berwenang menelaah tahap ini, atau biarkan kosong jika verifikator ditentukan saat pengusulan.
   * **Jika Mode Parallel**:
     * Ubah pilihan Mode ke `Parallel / Multi-Approval Quorum`.
     * Masukkan angka **Minimal Persetujuan (Quorum)** (contoh: `2`).
     * Centang nama-nama pegawai atau role yang masuk ke dalam pool verifikator.
4. Klik **"Simpan Tahapan"**.

---

## 5. CONTOH IMPLEMENTASI SESUAI JENIS NASKAH RUMAH SAKIT

### Contoh A: Standar Prosedur Operasional (SPO) Pelayanan Medis
* **Tingkat 1 (Serial)**: Verifikasi Kepala Instalasi terkait (SLA: 2 hari).
* **Tingkat 2 (Parallel Quorum)**: Verifikasi Komite Medik & Komite PMKP (Pool: 3 anggota, Min. Approval: 2, SLA: 3 hari).
* **Tingkat 3 (Penandatangan)**: TTE Direktur Utama.

### Contoh B: Surat Keputusan (SK) Kebijakan Direktur
* **Tingkat 1 (Serial)**: Verifikasi Tim Tata Usaha / Bagian Hukum RS.
* **Tingkat 2 (Serial)**: Verifikasi Manajemen / Wakil Direktur Terkait.
* **Tingkat 3 (Penandatangan)**: TTE Direktur Utama.

### Contoh C: Nota Dinas Internal
* **Tingkat 1 (Serial)**: Verifikasi Kepala Unit / Kepala Instalasi Pengusul.
* **Tingkat 2 (Penandatangan)**: TTE Kepala Unit Penerima / Manajemen Terkait.

---

## 6. STANDAR PENULISAN CATATAN PERSURATAN

Untuk menjaga profesionalitas tata naskah dinas, seluruh pengguna (Pengusul, Verifikator, Penandatangan) diharapkan menggunakan bahasa baku persuratan resmi rumah sakit:

| Jenis Catatan | Contoh Format Baku | Hal yang Harus Dihindari |
| :--- | :--- | :--- |
| **Persetujuan** | *"Telah diverifikasi dan disesuaikan dengan pedoman pelayanan RS. Disetujui untuk diteruskan."* | Singkatan informal (*"ok", "acc", "sip"*). |
| **Permintaan Revisi** | *"Mohon perbaiki konsiderans menimbang pada butir b agar merujuk pada Permenkes No. X Tahun 202X."* | Catatan tidak spesifik (*"salah, tolong benerin"*). |
| **Pengembalian TTE** | *"Dokumen dikembalikan: Lampiran daftar tilik belum dicantumkan secara lengkap. Mohon dilengkapi."* | Penolakan tanpa mencantumkan pasal/bagian yang keliru. |

---

*Dokumen panduan ini disusun sebagai acuan operasional tata kelola persuratan dinas elektronik SIMPEL-RS.*

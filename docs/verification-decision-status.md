# Status keputusan per level verifikasi

Pemeriksaan read-only pada 8 September 2026 untuk dokumen `tes penetrasi6`
(ID 10042, Kebijakan) menunjukkan dokumen sudah berada pada level 2:

- Tiket 10150, level 1, verifikator 10063: `disetujui`.
- Tiket 10152, level 2, verifikator yang sama: `menunggu`.
- Tahap level 2 menugaskan pengguna dengan role `verifikator`.

Temuan di atas adalah snapshot awal, bukan status terkini dokumen. Form yang
muncul kembali berasal dari penugasan level berikutnya kepada penerima sama.

Aturan yang diperbaiki: penerima tugas level lebih rendah tidak boleh memperoleh
tugas level lebih tinggi untuk dokumen dan versi yang sama, termasuk setelah
putaran pengembalian. Penyaringan berlaku untuk seluruh penerima sebelumnya,
termasuk anggota pool yang tiketnya dibatalkan karena kuorum telah terpenuhi.
Versi baru diperiksa berdasarkan penugasannya sendiri. Pengembalian ke level
sebelumnya membuat tiket baru; keputusan terdahulu tidak diaktifkan kembali.

Pembatasan berlaku pada pembuatan tiket, query antrean/dashboard, halaman detail,
dan validasi transaksi. Aktor delegasi yang memiliki tugas level lebih rendah
juga tidak dapat mengambil aksi level lebih tinggi. Jika pemeriksa berbeda
tidak mencukupi kuorum, transaksi dibatalkan dan tahap dokumen tidak berubah.

Halaman review menampilkan keputusan, level, nama tahap, dan pemberitahuan jika
tiket lama memiliki penugasan rangkap yang tidak dapat diproses.
Riwayat memiliki tautan ke tiket dan label level. Tiket selesai tidak memiliki
form keputusan. Respons detail memakai `no-store`; halaman yang dipulihkan dari
back/forward cache dimuat ulang untuk membaca status terbaru.

Regression tests mencakup penolakan akun pada dua level, tampilan tiket setelah
approval, pemisahan antrean/riwayat, pengembalian, serta rollback kuorum kurang.

Koreksi data existing harus dilakukan per dokumen:

```bash
php artisan verifikasi:close-overlaps DOCUMENT_ID
php artisan verifikasi:close-overlaps DOCUMENT_ID --apply
```

Perintah pertama hanya pratinjau. Perintah kedua menutup hanya tiket `menunggu`
yang rangkap, menyimpan alasan dan audit, serta menolak seluruh perubahan jika
pemeriksa berbeda tersisa tidak mencukupi. Keputusan historis tidak diubah.
Pemisahan penerima antarlevel tidak membutuhkan migration tambahan. Penambahan
atribusi keputusan rekan di bawah ini membutuhkan migration baru.

Validasi akhir: seluruh 110 test / 663 assertion lulus; Pint dan diff check lulus.
Pembacaan ulang SQL Server menunjukkan dokumen 10042 sudah `menunggu_ttd`,
tiket 10152 sudah `disetujui`, dan 10151 `batal`. Pratinjau koreksi tidak
menemukan tiket rangkap yang masih menunggu. Tidak ada perubahan data produksi
yang dilakukan; keputusan yang telah tercatat tidak dibatalkan secara retroaktif.

## Atribusi keputusan kepada pemeriksa lain

Migration `2026_09_08_000002_add_closing_decision_to_verifications` menambah
`closed_by_verification_id`. Saat persetujuan memenuhi kuorum, permintaan revisi,
atau pengembalian ke level sebelumnya menutup tiket lain, setiap tiket tersebut
menyimpan ID keputusan pemicu, pesan berisi judul/hasil/nama aktor aktual, serta
waktu penutupan. `direspon_at` tetap kosong karena penerima tidak mengambil
keputusan. Status internal `batal` dipertahankan untuk kompatibilitas; UI
menampilkan “Selesai melalui keputusan verifikator lain” dan pesan rinci.

Audit menyimpan tiket, keputusan, aktor ID/nama, versi, putaran dan status
keputusan. Notifikasi database untuk setiap penerima berisi pesan yang sama dan
tautan ke tiket miliknya. Riwayat dokumen, review, dan penandatangan menampilkan
tugas penerima lain sebagai bagian dari keputusan pemicu, bukan approval baru.
Untuk kuorum >1, pesan menjelaskan kuorum terpenuhi dan aktor keputusan terakhir.

Jalankan `php artisan migrate --force` sebelum mengaktifkan kode ini, lalu
bangun ulang cache view mengikuti prosedur deployment. Data lama tanpa tautan
tidak dihubungkan secara spekulatif kepada aktor tertentu; alasan yang memang
tersimpan tetap ditampilkan. Jika tidak ada alasan, UI menyatakan bahwa alasan
penutupan tidak tercatat pada data lama.

Migration `2026_09_08_000003_add_decision_actor_to_verifications` menambah
`decided_by_user_id`, terpisah dari migration sebelumnya agar deployment yang
sudah menjalankan migration 000002 tetap menerima perubahan schema. Setujui,
Minta Revisi, dan Ke Level Sebelumnya menyimpan user yang benar-benar bertindak.
Ini membedakan pemilik tiket dari pelaksana Plt./Plh. Tabel Riwayat Verifikasi
Saya menampilkan kolom “Diputuskan Oleh”; tiket rekan membaca aktor dari
keputusan pemicu. Keputusan lama menampilkan pemilik tiket disertai label
“Pemilik tiket · data lama”, karena aktor aktual tidak boleh ditebak.

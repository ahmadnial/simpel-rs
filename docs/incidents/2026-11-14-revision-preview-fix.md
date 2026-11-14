# Perbaikan revisi dan preview — 14 November 2026

Status: kode dan build lokal selesai serta teruji. Belum dideploy ke VM. Tidak ada migration, backfill, perubahan schema/data production, konfigurasi VM, atau dependency baru. Acuan audit: [laporan kasus 10112](2026-11-14-revision-preview-audit.md).

## Perilaku yang diperbaiki

- Download preview menampilkan progres, menyediakan Muat ulang selama pending, membatasi total download 150 detik dan aliran body yang tidak bergerak 30 detik. Render halaman pertama dibatasi 45 detik. Timeout/transfer terputus membatalkan request dan menyediakan Coba lagi. Respons lama tidak boleh mengaktifkan tombol setelah retry, timeout atau pemilihan berkas pengganti.
- Backend mengirim ukuran PDF lewat `X-Preview-Bytes`; klien memeriksa ukuran bytes hasil download sebelum menjalankan viewer. Header ini mengacu pada body setelah decoding HTTP sehingga tidak bergantung pada kompresi jaringan.
- PDF tanpa receipt menampilkan alasan pengajuan belum diizinkan. Receipt tetap diperlukan; tombol tidak dipaksa aktif ketika unduhan/render/validasi gagal.
- Lock konversi yang sedang dipegang request lain mengirim status `processing` dan Retry-After; frontend mencoba ulang maksimal tiga kali dengan jeda dua detik, kemudian memberi kontrol manual. Error konversi lain tidak memicu retry otomatis berulang.
- Pagehide membersihkan request/viewer; pageshow persisted memulai preview baru. Berkas pengganti yang sudah dipilih tetap mencegah pengajuan sumber lama; tombol upload dipulihkan setelah kembali melalui riwayat browser.
- Form detail dan transisi pengajuan memakai resolver tahap yang sama: template yang terikat pada dokumen dan tahap peminta revisi. Form menyebutkan tahap tujuan. Pilihan manual mengikuti tahap tersebut, bukan selalu tahap pertama. Template terikat yang kemudian dinonaktifkan tetap mengikuti perilaku backend yang sudah berlaku.
- Snapshot PDF yang sudah diajukan digunakan untuk preview/download, termasuk selama revisi. Riwayat tidak dikonversi ulang hanya karena status dokumen berubah. Preview historis tidak menerbitkan receipt untuk pengajuan versi aktif.
- Receipt terikat status, metadata dokumen, waktu perubahan dan ID tiket terakhir. Receipt sebelum revisi atau putaran revisi sebelumnya ditolak. Pengajuan ulang tanpa upload baru tetap boleh setelah preview baru; referensi snapshot tetap sama. Upload perbaikan masih mengikuti mekanisme versi yang sudah ada.
- Toggle penanda disembunyikan untuk snapshot yang sudah diajukan: PDF tersebut tetap merupakan salinan yang diperiksa sebelumnya. Untuk mengganti isi, unggah perbaikan.

Receipt lama sebelum rilis tidak memiliki konteks baru dan akan ditolak dengan pesan meminta preview ulang. Ini invalidasi bukti sementara, bukan perubahan data. Pengguna perlu reload halaman sesudah rilis.

## Verifikasi final

| Pemeriksaan | Hasil |
|---|---|
| PHPUnit seluruh unit/feature | **206 tes, 1.625 assertion lulus** |
| LibreOffice nyata: layout/marker, HTTP QR konfigurasi baru/lama, DOC round trip | **4 tes, 38 assertion lulus** |
| Browser viewer yang sudah ada | Lulus: tanggal/marker, replacement guard, retry, 92 halaman, canvas terbatas, layar 320–768 px |
| Browser regresi baru | Lulus: receipt hilang, bytes tidak lengkap, PDF rusak, pending, timeout, retry contention terbatas, sesi habis, lifecycle dan replacement |
| HTTP loopback dengan body sungguhan | Lulus: body stall diputus, koneksi terputus lalu retry berhasil, navigasi Back pulih |
| BFCache nyata Chrome | **`actual_bfcache_restore: true`**, preview dimuat ulang sekali |
| Vite production build | Lulus; entry `app-Ccm-FkE3.js` |
| Pint untuk seluruh PHP yang berubah, syntax JavaScript, git diff whitespace | Lulus |

Uji HTTP loopback mencatat dua stream yang dibatalkan klien. Pengujian timeout memakai jam virtual browser untuk mempercepat waktu; transfer menggunakan socket localhost sungguhan, bukan respons route tiruan. Chrome dijalankan tanpa flag default Playwright yang mematikan BFCache agar pemulihan Back dapat diperiksa nyata.

PHPUnit memaksa SQLite in-memory, fake storage dan cache pengujian; tidak mengakses database production. Tes Office dijalankan setelah suite aplikasi selesai agar fake storage tidak saling menghapus. Assertion tes Office dapat berbeda sedikit antar-run karena retry readiness server lokal ikut dihitung.

Tes yang ditambahkan/diubah:

- `tests/Feature/RevisionPreviewRegressionTest.php`: konteks receipt lintas dua putaran revisi, snapshot tetap satu, preview/download historis tanpa konverter, template nonaktif, revisi tahap atas otomatis, perubahan metadata.
- `tests/Unit/DocumentPdfServiceTest.php`: lock contention tidak memulai renderer kedua, header retry, berhasil setelah lock dilepas.
- `tests/browser/revision-preview-regression.cjs`: kegagalan dan pemulihan UI.
- `tests/browser/preview-transport.cjs`: transport HTTP nyata dan BFCache.
- `tests/browser/document-preview.cjs`: menunggu pesan error sebenarnya; tombol retry kini boleh terlihat selama pending.

Tes audit karakterisasi sebelumnya telah diubah menjadi tes regresi yang menuntut perilaku benar. Referensi nama `RevisionPreviewAuditTest.php` dan `revision-preview-audit.cjs` di laporan audit lama merupakan nama sebelum perbaikan.

## Batas klaim

Hasil ini membuktikan skenario yang diuji lulus, bukan jaminan bebas seluruh bug. Dokumen production 10112 belum diuji pada browser pengusul memakai build baru karena belum ada deployment. Penyebab jaringan/browser yang menghentikan transfer 13 November masih belum diketahui; perbaikan mencegah UI terjebak dan menyediakan pemulihan yang teruji, bukan menyatakan jaringan pengguna telah diperbaiki. Tidak dilakukan uji tulis ke SQL Server production atau uji beban.

## Rilis tanpa perubahan database

Perlu kode PHP, Blade, dan aset build dirilis bersama. `public/build` diabaikan Git pada repository ini: pull source saja tidak memasang hasil build baru. Build Vite sudah tersedia lokal. Pertahankan aset hash lama selama transisi agar tab pengguna yang masih terbuka tidak kehilangan dynamic import. Jangan menjalankan migration, seed, reset, restore, atau perintah penghapusan cache database.

Sebelum deployment yang disetujui: cocokkan commit dasar `8ab81dd913af62547d4b2b1f9eff4dad39ca1b07`, simpan cadangan file yang diganti beserta manifest build, dan pastikan working tree VM masih sesuai. Jangan menimpa perubahan operator yang muncul setelah audit. Config cache production harus tetap tersedia; rilis ini tidak memerlukan Composer install atau pengosongan config cache. Ikuti prosedur rilis aplikasi yang berlaku untuk compiled views/opcache.

Sesudah rilis: periksa login/health dan MIME aset baru, lalu reload browser pengusul untuk memeriksa preview 10112. Pemeriksaan visual/Network tidak memerlukan pengajuan atau modifikasi dokumen. Pengujian upload/pengajuan production tidak termasuk rilis tanpa perubahan data; gunakan lingkungan uji untuk transaksi tersebut.

Rollback: pulihkan kode, Blade dan manifest build dari cadangan rilis sebelumnya; pertahankan aset yang mungkin masih dipakai tab lama. Tidak ada rollback database karena tidak ada perubahan database dalam paket ini.

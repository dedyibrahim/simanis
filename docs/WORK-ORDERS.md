# Alur Buat Kerjaan

Satu pesanan dapat memuat beberapa pekerjaan. Setiap pekerjaan memiliki satu jenis: Notaris, PPAT, Legalisasi, atau Waarmerking; penghadap, penanggung jawab, jadwal, dan nomor dicatat per pekerjaan.

## Penggunaan

1. Buka menu Buat Kerjaan. Ikuti stepper: Jenis pekerjaan → Detail pekerjaan → Penghadap → Tinjau & simpan. Tombol Kembali memungkinkan koreksi tanpa menghapus isian.
2. Pada ringkasan, klik Simpan pesanan untuk menyimpan satu pekerjaan langsung. Untuk pesanan gabungan, klik Tambah pekerjaan lain ke pesanan ini, isi pekerjaan berikutnya, lalu simpan seluruh pesanan dari ringkasan. Tambah pekerjaan juga tersedia pada pesanan yang masih Proses.
3. Jadwalkan TTD. Ubah Jadwal memperbarui agenda yang sama. Batalkan Jadwal menghapus agenda dan mengembalikan pekerjaan ke draf tanpa menghapus penghadap atau pesanan.
4. Konfirmasi TTD, lalu Ambil Nomor. Form buku terisi dari pekerjaan. Penghadap harus tetap sesuai data pekerjaan dan nomor hanya dapat ditautkan sekali.
5. Setelah semua pekerjaan bernomor, klik Selesaikan Pesanan. Tindakan ini mempertahankan harga yang telah dimasukkan, memakai tanggal pada buku untuk detail invoice, dan menandai buku terkait sebagai Selesai.
6. Invoice diproses melalui modul pesanan/invoice yang ada. Pada pesanan baru, menyimpan invoice atau detail tidak otomatis menyelesaikan pesanan atau buku. Pesanan lama mempertahankan perilaku sebelumnya dan tersedia melalui tautan Pesanan lama.

Pembatalan agenda yang telah tersinkron ke Google Calendar harus berhasil di Google sebelum agenda lokal dihapus. Jika sinkronisasi gagal, agenda dan status lokal tetap dipertahankan untuk dicoba kembali. Jadwal yang sudah dikonfirmasi TTD tidak dapat dibatalkan melalui aksi ini.

## Aktivasi

Jalankan dari direktori backend setelah database tersedia:

```powershell
php artisan migrate --path=database/migrations/2026_11_26_100000_create_work_order_items.php
```

Migrasi menambahkan tabel work_order_items dan work_order_participants, tanpa memindahkan pesanan lama maupun mengubah nomor buku yang sudah ada. Gunakan proses deployment aplikasi yang berlaku untuk memperbarui backend dan frontend.

## Verifikasi lokal

```powershell
# Dari backend
php artisan test --filter='WorkOrderTest|ClientKtpTest|PpatEditTest|BookDateEditTest'
# Dari frontend
npm run typecheck
node --test tests/workOrderStepper.test.mjs
```

Tes WorkOrderTest memakai SQLite in-memory dan mock Google Calendar; hasil lulus tidak membuktikan koneksi MySQL atau sinkronisasi Google Calendar nyata. Verifikasi UI dengan data uji setelah migrasi: buat pesanan dua jenis, ubah/batalkan/jadwalkan ulang agenda, konfirmasi TTD, ambil nomor masing-masing, selesaikan pesanan, dan periksa detail invoice.

## Hasil audit 26 November 2026

Perbaikan yang diverifikasi:

- API autocomplete menerima pencarian mulai dua karakter dan parameter GET maupun POST.
- Menyimpan ulang invoice tax/non-tax memakai ID tersimpan, tanpa meminta ID dari form atau mengubah ID berdasarkan kiriman client.
- Pengambilan nomor dari pekerjaan mempertahankan penanggung jawab pekerjaan saat diinput Admin; endpoint ini menolak ID buku lama agar hanya membuat nomor baru.
- Urutan ID buku, penghadap, dan pesanan membaca seluruh angka setelah prefiks tiga huruf, termasuk urutan di atas 10.000. ID lama tidak diubah.
- Penyelesaian pesanan memakai nomor/tanggal dari buku dan memperbarui status buku dalam transaksi yang sama. Harga detail tetap dipertahankan.
- Daftar mengembalikan jadwal saat ini sehingga Ubah jadwal tidak membuka isian kosong.
- Respons daftar bulan yang terlambat tidak menimpa bulan terbaru yang dipilih.
- Validasi server menolak jenis akta yang tidak sesuai layanan, angka negatif, tanggal tidak valid, penghadap duplikat, dan penghadap yang mewakili dirinya sendiri.

Verifikasi akhir: 45 tes backend, 18 tes frontend, dan pemeriksaan tipe frontend lulus. Uji MySQL lokal untuk membuat/memuat pesanan serta membuat dan menyimpan ulang invoice tax/non-tax berhasil dalam transaksi yang di-rollback; jumlah baris diperiksa setelah rollback. Notifikasi pada tes penomoran menggunakan mock. Sinkronisasi Google Calendar nyata dan pemeriksaan visual browser belum termasuk verifikasi ini.

## Deployment production — 26 November 2026

Rilis: work-orders-20261126-1795683102518. Frontend statis dibangun dengan API http://192.168.0.12:8000/api dan dipasang pada node aktif 192.168.0.11 serta standby 192.168.0.10. Backend tetap memakai container simanis-php83-fpm (PHP 8.3.36).

Migrasi create_work_order_items dijalankan hanya pada node aktif dan tereplikasi ke standby, batch 28. Source backend dan hash halaman frontend identik di kedua node. Pemeriksaan baca daftar pekerjaan, jenis akta Notaris/PPAT, dan daftar kedua jenis client berhasil pada kedua node. Halaman VIP merespons 200; API tanpa autentikasi merespons 401 sesuai harapan. Replikasi IO/SQL sehat dengan lag 0 detik setelah deployment.

Backup source, frontend, dan skema database sebelum rilis tersedia di /var/backups/simanis/work-orders-20261126-1795683102518 pada kedua node. Frontend sebelumnya juga dipertahankan di /var/www/frontendnotaris-before-work-orders-20261126-1795683102518. Konfigurasi .env, upload, serta layanan WhatsApp tetap dipertahankan. Aset _nuxt dan gambar lama disertakan agar sesi browser yang masih memakai versi sebelumnya tidak kehilangan aset.

Jika rollback diperlukan, pulihkan source dan frontend dari backup node yang bersangkutan; pertahankan tabel baru beserta data yang mungkin sudah dibuat. Jangan melakukan rollback migrasi secara otomatis karena dapat menghapus pekerjaan/penghadap baru. Uji transaksi melalui browser dan sinkronisasi Google Calendar nyata tetap perlu dilakukan oleh pengguna dengan akun yang sesuai.

## Edit draf, hapus pekerjaan, dan dialog aplikasi

- Selama status masih Draf/Belum dijadwalkan, pemilik pesanan atau Admin dapat memilih Edit draf, mengoreksi langkah form, lalu Simpan perubahan. Setelahnya, jadwalkan TTD, konfirmasi TTD ketika sudah berlangsung, dan ambil nomor.
- Sesudah Jadwal TTD atau Sudah TTD, pengeditan draf dikunci. Tombol Hapus pekerjaan tersedia bagi pemilik pesanan/Admin selama pekerjaan belum bernomor dan pesanan masih Proses. Pekerjaan bernomor tidak dapat dihapus melalui tombol ini.
- Penghapusan menghapus penghadap pekerjaan dan agenda terkait, tanpa menghapus data master client. Jika agenda telah tersinkron dengan Google Calendar, penghapusan remote harus berhasil terlebih dahulu.
- Jika pekerjaan terakhir dihapus, pesanan kosong ikut dihapus hanya bila belum memiliki detail, invoice, atau dokumen. Jika masih ada relasi tersebut, penghapusan ditolak dan data dipertahankan.
- Popup browser bawaan untuk konfirmasi dan pemberitahuan telah diganti dengan dialog aplikasi bersama. Tombol Batal/Escape menolak tindakan; navigasi halaman membatalkan keputusan yang tertunda. Klik ganda pada konfirmasi yang sama tidak mengotorisasi dua tindakan.

Rilis production: work-dialogs-20261126-1795687120647, kedua node .11 dan .10, tanpa migrasi baru. Backup source/ frontend/skema tersedia di /var/backups/simanis/work-dialogs-20261126-1795687120647. Verifikasi: 49 tes backend, 23 tes frontend, pemeriksaan tipe, dan build production lulus. Halaman VIP/aset merespons 200, endpoint DELETE tanpa login merespons 401, dan source/hash halaman identik pada kedua node. Tidak ada data production yang dihapus saat pemeriksaan deployment.


## Notifikasi WhatsApp tahap pekerjaan (26 November 2026)

Rilis `work-wa-20261126` terpasang di server aktif 192.168.0.11 dan standby 192.168.0.10. Menggunakan gateway WAHA dan nomor telepon pengguna yang sudah ada. Penerima: penanggung jawab pekerjaan, pemilik pesanan, serta Admin/Super Admin; nomor yang sama dinormalisasi dan dikirim sekali per perubahan. Pengguna tanpa nomor dilewati.

Notifikasi dikirim sesudah transaksi terluar berhasil commit: membuat/memperbarui draf, membuat/mengubah/membatalkan jadwal TTD, konfirmasi TTD, mengambil nomor, menyelesaikan pesanan, menghapus pekerjaan, dan menghapus nomor (kembali siap ambil nomor). Pergantian langkah form yang belum disimpan tidak mengirim pesan. Pesan memuat identitas pesanan, judul dan jenis pekerjaan, penanggung jawab, jadwal/lokasi atau nomor jika tersedia, pelaku, dan waktu. Tidak mengirim NIK atau gambar KTP.

Notifikasi nomor lama dinonaktifkan hanya pada jalur ambil nomor dari pekerjaan agar tidak ganda. Kegagalan gateway tidak membatalkan pekerjaan; hasil pengiriman dicatat melalui log WAHA, exception dicatat di log aplikasi. Belum ada antrean persisten atau retry otomatis; pesan gagal perlu ditindaklanjuti dari log.

Validasi: 51 tes backend lulus, termasuk deduplikasi penerima, pengiriman setelah commit, tidak mengirim setelah rollback, dan kegagalan satu penerima tidak menghentikan penerima berikutnya. Kedua server lulus lint PHP dan pemeriksaan endpoint terlindungi (401 tanpa login); hash ketiga file identik. Tidak mengirim pesan uji ke nomor pengguna produksi. Backup backend sebelum rilis: `/var/backups/simanis/work-wa-20261126/backend-before.tar.gz` pada masing-masing server.


## Upload dokumen penghadap dari dialog pekerjaan (26 November 2026)

Rilis frontend `participant-docs-20261126`: dialog Upload Dokumen pada buku Notaris, PPAT, Legalisasi, dan Waarmerking memiliki tab Dokumen pekerjaan dan Dokumen penghadap. Tab penghadap memilih pemilik dari daftar penghadap serta pihak yang diwakili, menggabungkan ID client yang sama, lalu mengunggah beberapa file langsung melalui API `UploadDokumenClient` yang digunakan modul Data Client. Dokumen masuk ke `tb_berkas` dan folder client, serta langsung muncul pada daftar dokumen client di dialog dan Data Client. Tidak membuat salinan sebagai dokumen pekerjaan. Daftar dokumen dapat di-refresh dan dibuka. Selama upload, pergantian pemilik/tab dan penutupan dialog diblokir. Respons daftar client yang terlambat diabaikan agar tidak tertukar.

Validasi: vue-tsc lulus; 23 tes frontend yang ada lulus; Nuxt generate lulus. Terpasang pada 192.168.0.10 dan 192.168.0.11; halaman buku_akta HTTP 200, hash 200.html identik. Backup frontend tersedia di `/var/backups/simanis/participant-docs-20261126/frontend-before` pada masing-masing server. Belum dilakukan uji upload melalui browser dengan akun produksi.


## Penghapusan modul Garis Otomatis Akta (26 November 2026)

Atas permintaan pengguna, menu dan katalog modul Garis Otomatis Akta dihapus, halaman Vue dihapus, serta binding layanan frontend dan rute API proses dinonaktifkan. Middleware frontend menolak URL lama di lokal; aturan Apache menolak URL lama di produksi dengan 404. Rilis `remove-garis-20261126` dipasang pada server standby 192.168.0.10 dan aktif 192.168.0.11, dengan backup frontend dan routes/api.php di `/var/backups/simanis/remove-garis-20261126`. Typecheck dan Nuxt generate lulus. Pemeriksaan kedua server: buku_akta 200, halaman garis-otomatis-akta 404, endpoint proses 404.


## Sinkronisasi jadwal Buat Kerjaan dan Jadwal Notaris (26 November 2026)

Kedua modul menggunakan satu baris `events` yang ditautkan melalui `work_order_items.event_id`. Perbaikan `work-calendar-sync-20261126` mengarahkan update/hapus event terkait pekerjaan melalui alur WorkOrderController. Perubahan waktu/lokasi dari kalender memakai validasi dan pemeriksaan bentrok yang sama; penghapusan jadwal dari kalender atau chatbot mengembalikan pekerjaan ke draf dan melepas event, tanpa menghapus pekerjaan/penghadap. Hapus pekerjaan dari Buat Kerjaan tetap menghapus event/pesertanya. Jadwal yang sudah dikonfirmasi TTD tidak dapat diubah/dihapus melalui kalender. Judul dan penanggung jawab tetap mengikuti pekerjaan; perubahan keduanya melalui kalender ditolak dengan pesan penjelasan. Notifikasi WA tahap mengikuti mekanisme after-commit yang sudah ada.

Validasi: 53 tes backend lulus, termasuk membuat jadwal dari pekerjaan lalu membaca kalender, mengubah dari kalender lalu membaca pekerjaan, menghapus dari kalender, menjadwalkan ulang tanpa duplikat, menghapus pekerjaan beserta event, serta menolak penghapusan jadwal yang sudah TTD. Lint dan pemeriksaan API terlindungi lulus pada kedua server produksi; hash EventController identik. Pemeriksaan jadwal berstatus scheduled tanpa event menemukan 0 baris di kedua server sehingga tidak ada koreksi data lama. Backup controller: `/var/backups/simanis/work-calendar-sync-20261126/EventController-before.php`.


## Revalidasi halaman dialog upload (26 November 2026)

Laporan tab Dokumen penghadap tidak terlihat di Legalisasi/Waarmerking: pemeriksaan menemukan dialog terbaru sudah terpasang dan dirujuk entry JavaScript produksi, sedangkan screenshot menunjukkan tampilan lama. Cache browser/tab lama diduga menjadi penyebab; belum diverifikasi langsung di browser pengguna. Ditambahkan header `Cache-Control: no-cache, must-revalidate` untuk HTML melalui frontend/public/.htaccess dan diaktifkan mod_headers pada kedua server produksi. Halaman Notaris, Legalisasi, dan Waarmerking terverifikasi HTTP 200 dengan header revalidasi. Tab yang sudah terbuka sebelum rilis perlu dimuat ulang (Ctrl+F5) satu kali untuk mengganti runtime JavaScript lama. Backup aturan sebelumnya: `/var/backups/simanis/dialog-cache-20261126/htaccess-before`.


## Upload fullscreen dengan kartu per penghadap (26 November 2026)

Rilis `upload-ux-20261126` mengganti pilihan dropdown pemilik dokumen dengan kartu terpisah untuk tiap penghadap/pihak yang diwakili. Semua kartu langsung tersedia pada tab Dokumen penghadap; ID client yang sama digabung menjadi satu kartu. Masing-masing kartu memiliki daftar file pilihan, tombol batalkan per file sebelum upload, tombol upload, status/error, refresh, serta daftar dokumen tersimpan. Input, dokumen, dan hasil upload tiap client terisolasi; beberapa upload dapat berjalan bersamaan dan dialog tetap terkunci sampai seluruh upload selesai. Dialog upload semua modul sekarang fullscreen dengan konten dapat di-scroll. Dokumen tetap memakai API Data Client yang sama.

Validasi: typecheck dan Nuxt generate lulus; 26 tes frontend lulus termasuk 3 tes baru untuk deduplikasi pemilik, penguncian beberapa upload, isolasi ID/file tiap client, serta kegagalan yang mempertahankan file untuk retry. Dipasang pada 192.168.0.10 dan 192.168.0.11; halaman buku_akta HTTP 200 dan hash entry HTML identik. Backup `/var/backups/simanis/upload-ux-20261126/frontend-before`. Belum dilakukan uji visual browser atau upload menggunakan akun produksi.


## Izin edit dan hapus nomor (26 November 2026)

Rilis `number-owner-20261126`: Asisten hanya dapat membuka form edit, menyimpan perubahan, dan menghapus nomor milik sendiri. Pemilik menggunakan id_user pada buku Notaris, PPAT, Legalisasi, Waarmerking; buku Surat menggunakan pengirim. Admin dan Super Admin/SuperAdmin dikecualikan dari batas kepemilikan. Arsip tidak dapat mengedit/menghapus nomor. Pembatasan berlaku pada tombol dan pemanggilan form di frontend, serta pada endpoint form edit, simpan perubahan, dan hapus keenam buku di backend. Pemilik dibaca dari database, bukan dari payload. Aturan nomor terakhir untuk hapus Asisten tetap berlaku; Admin/Super Admin boleh mengelola nomor lintas pemilik. Perlindungan nomor terkait pesanan selesai tetap berlaku.

Validasi: 55 tes backend lulus, termasuk matriks 18 endpoint yang menolak pengguna lain dan kebijakan pemilik/admin/Arsip; 26 tes frontend lulus, typecheck dan generate lulus. Terpasang pada standby 192.168.0.10 dan aktif 192.168.0.11; frontend 200, API edit tanpa login 401, hash controller dan entry HTML identik. Backup frontend dan PembuatanNomor tersedia di `/var/backups/simanis/number-owner-20261126`. Tidak melakukan edit/hapus data produksi sebagai pengujian.

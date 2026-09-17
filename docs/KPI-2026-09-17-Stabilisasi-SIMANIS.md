# KPI Pengembangan SIMANIS - 17 September 2026

| Pekerjaan | Hasil | Verifikasi |
| --- | --- | --- |
| WhatsApp Blast | Menu broadcast untuk Super Admin dapat memilih penerima lintas level, menulis pesan, dan memantau pengiriman | Pengujian API, build production, dan deployment dua node |
| OTP login WhatsApp | OTP login per user dapat diaktifkan Admin/Super Admin; kode berlaku 5 menit, memiliki batas percobaan dan jeda kirim ulang | Migration, pengujian alur autentikasi, serta perbaikan izin cache Laravel pada `.10` dan `.11` |
| Reminder agenda HA | Pengiriman reminder pagi, siang, dan sore berjalan dari node primary saja sehingga tidak terkirim ganda | Cron pada dua node, primary guard VIP, dan pemeriksaan WAHA |
| Preview dokumen scan | Halaman preview scan menyediakan navigasi kembali | Build production dan HTTP 200 melalui VIP `.12` |
| Laporan dokumen pekerjaan | Semua laporan reportorium menandai pekerjaan tanpa dokumen dengan teks merah dan keterangan | PHP lint dan pemeriksaan PDF hasil cetak |
| Expand baris reportorium | Expand menggunakan identitas pekerjaan unik sehingga hanya baris yang dipilih yang terbuka | Build production dan bundle identik pada `.10`/`.11` |
| Standardisasi NPWP badan hukum | Form menyediakan status NPWP; NPWP valid wajib 15/16 digit dan placeholder ditolak | 475 data tanpa NPWP distandarkan tanpa mengganti `id_client`; replikasi SQL aktif |
| Perlindungan relasi client | Normalisasi NPWP hanya mengubah status dan nomor identitas, tanpa mengubah primary key client | Hitungan relasi sebelum/sesudah identik pada dokumen, reportorium, penghadap, dan bantek |
| Konsistensi tabel Tanda Terima | Kolom sticky Aksi mengikuti warna header dan baris pada tema aktif | Build production pada `.10` dan `.11` |
| Audit file Tanda Terima | Dua referensi file dangling dibersihkan setelah file dipastikan tidak ada di kedua server dan kedua MinIO | Audit 1.823 record berfile terhadap 1.833 object; VIP `.12` HTTP 200 |

Seluruh perubahan aktif pada server `.10` dan `.11`. Replikasi database pada
standby berstatus aktif, sedangkan akses aplikasi melalui VIP `.12` telah
diverifikasi setelah deployment.

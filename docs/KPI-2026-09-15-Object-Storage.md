# KPI Pengembangan SIMANIS - 15 September 2026

| Pekerjaan | Hasil | Verifikasi |
| --- | --- | --- |
| Penyelesaian replikasi tanda terima | Object `ASLI TTD SERTEL TO BSI-2.pdf` yang tertinggal disalin ke MinIO standby | `tandaterima` sama, 1.833 object pada kedua server |
| Aktivasi MinIO pada server `.10` | Backend standby memakai disk `documents` dengan endpoint MinIO lokal | Probe baca Laravel berhasil pada seluruh 10 prefix |
| Pembersihan folder public `.10` | Sepuluh folder dokumen lokal yang telah dimigrasikan dihapus | Disk turun dari 459 GB menjadi 305 GB; frontend dan API HTTP 200 |
| Optimasi pemantauan MinIO | Timer migrasi lama dinonaktifkan dan pemeriksaan replikasi berat diubah dari setiap 1 menit menjadi 5 menit | Load aplikasi turun; MinIO, Apache, MySQL, dan frontend tetap sehat |
| Pusat kontrol Object Storage | Progress folder public pada card server dihapus; arah Main `.11` ke Standby `.10`, status 10 modul, modul yang berbeda, dan tombol sinkronisasi selektif tersedia pada satu card | Build frontend, lint backend, dan uji launcher sinkronisasi background |
| Optimasi SearchData | Pencarian lintas tujuh kategori menggunakan lookup ID client dan subquery buku tanpa join penghadap yang menghasilkan data besar | `sanggar` 0,383 detik, data awal 0,198 detik, `PT` 0,263 detik, dan `legalisasi` 0,370 detik |
| Perbaikan tombol Kembali pencarian | Tombol Kembali dan logo pada layout khusus pencarian melakukan navigasi penuh ke dashboard agar layout utama selalu dipulihkan | Build production dan uji akses dashboard pada kedua server |
| Detail penghadap dan menu konteks | Kategori buku menampilkan client beserta akses Lihat Detail ke data buku/penghadap; menu klik kanan mengikuti tema terang dan gelap dengan kontras yang jelas | Build production dan uji halaman pencarian dokumen |
| Riwayat buku per jenis client | Menu Perorangan dan Badan Hukum menampilkan setiap client, kategori buku yang pernah melibatkannya, jumlah data, serta akses langsung ke detail penghadap | Build production dan uji mode grid/list pencarian dokumen |
| Data awal kategori buku | Legalisasi, Waarmerking, Akta Notaris, dan Akta PPAT mengambil client berdasarkan keterlibatan buku terbaru pada kategori aktif | Uji API per kategori, build production, dan verifikasi kedua server |
| Data awal jenis client | Perorangan dan Badan Hukum mengambil 15 client terbaru langsung berdasarkan jenis yang dipilih, bukan memfilter sampel client umum | Uji API jenis client, build production, dan verifikasi kedua server |
| Deduplikasi data buku pencarian | Hasil detail Notaris, Legalisasi, Waarmerking, dan PPAT hanya menampilkan satu card untuk setiap buku meskipun client memiliki beberapa relasi penghadap | PHP lint, build production, dan verifikasi kedua server |
| Pembersihan dokumen 404 pencarian | 180 record dokumen yatim dihapus dengan backup, hasil pencarian/detail buku memfilter object yang tidak tersedia, dan dialog dokumen client kembali memakai card thumbnail dengan preview, download, dan keranjang | Audit 59.115 referensi storage, PHP lint, build production, dan verifikasi kedua server |

Total replikasi terverifikasi: 68.857 object dan 168.373.635.769 byte pada
MinIO `.11` maupun `.10`.

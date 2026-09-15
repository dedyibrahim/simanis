# KPI Pengembangan SIMANIS - 15 September 2026

| Pekerjaan | Hasil | Verifikasi |
| --- | --- | --- |
| Penyelesaian replikasi tanda terima | Object `ASLI TTD SERTEL TO BSI-2.pdf` yang tertinggal disalin ke MinIO standby | `tandaterima` sama, 1.833 object pada kedua server |
| Aktivasi MinIO pada server `.10` | Backend standby memakai disk `documents` dengan endpoint MinIO lokal | Probe baca Laravel berhasil pada seluruh 10 prefix |
| Pembersihan folder public `.10` | Sepuluh folder dokumen lokal yang telah dimigrasikan dihapus | Disk turun dari 459 GB menjadi 305 GB; frontend dan API HTTP 200 |

Total replikasi terverifikasi: 68.857 object dan 168.373.635.769 byte pada
MinIO `.11` maupun `.10`.

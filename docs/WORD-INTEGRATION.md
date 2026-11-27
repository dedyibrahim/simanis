# SIMANIS Word Data — preview 0.5.3

Tanggal: 27 November 2026. Pengguna mengizinkan deploy API produksi melalui HTTP LAN; HTTPS tidak diwajibkan untuk IP privat kantor.

## Cara menggunakan

1. Ekstrak seluruh ZIP ke folder lokal.
2. Simpan dokumen dan tutup Word. Jalankan Install-SIMANIS-Word.cmd (akun Windows Anda, tanpa administrator).
3. Pemasang mencadangkan add-in SIGANDI/SIMANIS sebelumnya, menyalin template ke Startup Word, dan mendaftarkan helper di HKCU Software/SIMANIS/WordDataExe.
4. Buka Word dan dokumen yang dapat diedit. Gunakan tab SIMANIS > Pekerjaan / Data Draf.
5. Atur alamat API. Default LAN: http://192.168.0.12:8000/api. IP/port bisa diganti. Setelah login berhasil, alamat API dan path sertifikat publik disimpan untuk pemakaian berikutnya; password/OTP/token tidak ikut disimpan.
6. Jika server memakai CA internal, pilih file sertifikat publik PEM/CRT. Jangan pilih atau membagikan private key.
7. Login akun SIMANIS; masukkan OTP WhatsApp jika akun mewajibkannya.
8. Tampilan awal Pekerjaan Saya memuat Notaris, PPAT, Legalisasi dan Waarmerking dari operasional kantor. Cari pekerjaan/pesanan atau nama penghadap, lalu pilih pekerjaan. Mode Penghadap dan Transaksi PPAT tetap tersedia.
9. Pilih hasil dan tinjau field. Ctrl+klik/Shift+klik memilih beberapa field. Dengan label menghasilkan "Nama: ..."; tanpa label hanya nilainya.
10. Klik Masukkan pilihan ke Word. Data masuk setelah posisi seleksi, tanpa menghapus teks yang dipilih. Ctrl+Z membatalkan satu tindakan sisipan.

File SIMANIS-WordData.exe juga dapat dibuka sendiri untuk menyalin data. Tombol desktop Data SIMANIS memakai pemilih yang sama.

## Akses dan data

Persetujuan pengguna: Asisten hanya untuk pekerjaan/nomor miliknya; Admin dan Super Admin/SuperAdmin untuk semua; Arsip ditolak.
Server membatasi daftar serta detail berdasarkan id_user pekerjaan/buku. Client dan pihak yang diwakili harus terlibat dalam pekerjaan/nomor milik Asisten.
Field: nama, identitas/NIK, alamat dan data KTP yang diizinkan; kedudukan/pihak yang diwakili; objek PPAT, luas, harga transaksi, NOP/NJOP, BPHTB/PPh.
Field kosong tidak dibuat-buat. PPAT belum bernomor berasal dari Buat Kerjaan; PPAT bernomor memakai data buku yang sebenarnya agar tidak tampil ganda.
Ini penyisipan field berlabel/nilai, bukan pembuatan redaksi hukum atau penggantian placeholder template secara otomatis.

## Keamanan dan kompatibilitas

HTTP diizinkan hanya untuk IP privat RFC1918 atau loopback sesuai instruksi pengguna. HTTP tidak terenkripsi: gunakan hanya LAN kantor tepercaya. HTTPS tetap didukung dengan pemeriksaan sertifikat dan nama host/IP; redirect tidak diikuti.
Password, OTP dan bearer token tidak disimpan di disk/registry. Sesi dibuka saat login dan hanya token sesi tersebut dicabut saat helper ditutup.
File hasil sementara hanya berisi pilihan pengguna dalam UTF-16, lalu dihapus setelah dibaca Word.
Jika dokumen aktif atau teksnya berubah selama pemilihan, penyisipan dibatalkan. Dokumen terkunci/hanya baca ditolak.
Pemasang tidak mengubah macro policy, Trusted Locations, Trust Access VBA, execution policy Windows, atau certificate store.
Jalankan hanya macro yang diizinkan kebijakan kantor. Jika PowerShell diblokir kebijakan kantor, gunakan proses instalasi yang disetujui administrator; jangan menonaktifkan kebijakan.
ID macro/shape dipertahankan agar dokumen lama tetap kompatibel. Sistem aktivasi desktop dan Word dihapus untuk edisi SIMANIS mandiri sesuai instruksi pengguna.
Fitur desktop dan garis Word langsung digunakan tanpa lisensi/aktivasi. Login SIMANIS tetap diperlukan hanya untuk mengakses data penghadap/transaksi sesuai hak akun.
Build desktop mandiri tidak membutuhkan atau menyertakan .env lisensi, secret, cache aktivasi, atau bridge lisensi. Paket desktop portable berisi SIMANIS.exe dan paket Word opsional.

## Backend yang disiapkan di repo SIMANIS

- GET /api/auth/word/jobs?q=...
- GET /api/auth/word/jobs/{id}
- GET /api/auth/word/clients?q=...
- GET /api/auth/word/clients/{id}
- GET /api/auth/word/ppat?q=...
- GET /api/auth/word/ppat/{work|book}/{id}
- DELETE /api/auth/word/session (hanya token saat ini)
Semua memakai auth:sanctum, throttle dan respons data no-store. Tidak membutuhkan perubahan skema.

API menggunakan WordDataController.php dan rute terkait pada server SIMANIS. Konfigurasi helper ke http://192.168.0.12:8000/api untuk LAN; sertifikat tidak diperlukan untuk HTTP.
Deploy API HTTP LAN diizinkan pengguna pada 27 November 2026; tidak mengubah pengaturan HTTPS server.

## Hasil pemeriksaan

- 58 tes backend SIMANIS lulus, termasuk scope pemilik, pihak yang diwakili, role admin/Arsip, field allowlist dan autentikasi.
- 48 tes Python desktop/helper lulus, termasuk startup desktop dengan jaringan diblokir, tidak ada gerbang aktivasi pada Word, HTTPS, OTP, token header/session, Unicode dan baris baru.
- Builder memverifikasi source VBA paket identik, metadata/ribbon SIMANIS ada, tanpa handler auto-run.
- Template dibuka pada instance Word terpisah dengan macro dinonaktifkan, ditutup tanpa simpan; hash file tidak berubah.
- GUI helper berhasil dibentuk dan sintaks PowerShell pemasang diperiksa.
- API telah dipasang di node 192.168.0.10 dan 192.168.0.11; endpoint tanpa autentikasi merespons 401 melalui VIP 192.168.0.12. Login Word dan macro penyisipan end-to-end masih perlu dicoba dengan akun pengguna.


## Edisi mandiri 1.5.1 / Word 0.5.1

Atas instruksi pengguna, seluruh gerbang aktivasi desktop dan Word dilepas, termasuk pemeriksaan online, pembacaan cache/key, CLI bridge dan menu lisensi. Tidak membuat status aktivasi palsu atau key pengganti. Build tidak memuat .env/secret. Login SIMANIS tetap hanya untuk akses data penghadap/PPAT. Pengguna secara eksplisit menyetujui penggantian tes lisensi lama dengan tes edisi mandiri. 48 tes Python lulus, termasuk startup GUI desktop dengan akses jaringan diblokir dan tanpa cache aktivasi; tes memastikan kode Word tidak memiliki gerbang aktivasi. Paket desktop portable disiapkan dengan SIMANIS.exe dan Word opsional. Deploy API HTTP LAN diizinkan pada tahap berikutnya oleh pengguna.

## Lokasi aplikasi

Sumber desktop dan add-in Word berada di folder desktop/, sejajar backend/ dan frontend/. Kedua folder clone Radina telah dihapus setelah sumber dan build dipindahkan serta diverifikasi.

- Desktop portable: desktop/output/SIMANIS-Desktop-1.5.2-portable.zip
- Add-in Word: desktop/output/SIMANIS-Word-0.5.2-preview.zip
- Executable desktop: desktop/dist/SIMANIS-v1.5.2/SIMANIS.exe

## Deploy HTTP LAN 27 November 2026

API Word diaktifkan pada kedua node produksi dengan backup /var/backups/simanis/word-data-20261127. Tidak mengubah skema database atau konfigurasi HTTPS. Helper default ke http://192.168.0.12:8000/api; HTTP ditolak untuk domain/IP publik dan link-local. Paket baru: Desktop 1.5.2 / Word 0.5.2.

Pemeriksaan produksi read-only: daftar/detail client dan PPAT berhasil untuk Asisten dan Admin pada database produksi; tidak membuat token, tidak mengubah data. Halaman Buat Kerjaan tetap 200. Integritas ZIP baru dan hash sumber helper diverifikasi; template Word 0.5.2 dapat dibuka dengan macro dinonaktifkan.

## Installer gabungan 1.5.3

Jalankan SIMANIS_Setup_v1.5.3.exe untuk memasang desktop, helper data dan add-in Word sekaligus pada akun Windows saat ini. Tidak perlu menjalankan CMD atau mengatur pointing lagi; default http://192.168.0.12:8000/api. Tutup Word sebelum instalasi. Setelah membuka Word, pilih SIMANIS > Pekerjaan / Data Draf lalu login dan pilih Pekerjaan Saya. Installer mencadangkan template SIMANIS/SIGANDI sebelumnya dan tidak mengubah keamanan macro.

Data masuk sebagai field teks yang dipilih untuk membantu menyusun draf; belum mengganti placeholder template atau menyusun redaksi akta otomatis. Password dan token tetap tidak disimpan.

Verifikasi rilis 1.5.3: 48 tes desktop/helper dan 4 tes API Word lulus. Installer nyata diuji dua kali dalam folder terisolasi: instalasi pertama, upgrade, hash semua aplikasi/template, backup legacy/SIMANIS, pelestarian template lain dan registry pengguna. Semua lulus. Endpoint pekerjaan sudah aktif di kedua node; query daftar/detail produksi diuji dalam transaksi read-only tanpa perubahan data. Backup server: /var/backups/simanis/word-jobs-20261127. Login Word dan penyisipan macro dengan akun pengguna belum diuji end-to-end.

# SIMANIS Word Data — preview 0.5.1

Tanggal: 27 November 2026. Aplikasi disiapkan lokal; API dan HTTPS produksi belum dipasang sesuai permintaan pengguna.

## Cara menggunakan

1. Ekstrak seluruh ZIP ke folder lokal.
2. Simpan dokumen dan tutup Word. Jalankan Install-SIMANIS-Word.cmd (akun Windows Anda, tanpa administrator).
3. Pemasang mencadangkan add-in SIGANDI/SIMANIS sebelumnya, menyalin template ke Startup Word, dan mendaftarkan helper di HKCU Software/SIMANIS/WordDataExe.
4. Buka Word dan dokumen yang dapat diedit. Gunakan tab SIMANIS > Penghadap / Transaksi PPAT.
5. Atur alamat API HTTPS. Default sementara: https://192.168.0.12:8443/api. IP/port bisa diganti. Setelah login berhasil, alamat API dan path sertifikat publik disimpan untuk pemakaian berikutnya; password/OTP/token tidak ikut disimpan.
6. Jika server memakai CA internal, pilih file sertifikat publik PEM/CRT. Jangan pilih atau membagikan private key.
7. Login akun SIMANIS; masukkan OTP WhatsApp jika akun mewajibkannya.
8. Pilih Penghadap atau Transaksi PPAT, lalu cari nama client, nama pekerjaan/pesanan, nomor akta atau hak milik.
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

HTTPS wajib, sertifikat dan nama host/IP diperiksa. Tidak ada fallback HTTP atau verify=False; redirect tidak diikuti.
Password, OTP dan bearer token tidak disimpan di disk/registry. Sesi dibuka saat login dan hanya token sesi tersebut dicabut saat helper ditutup.
File hasil sementara hanya berisi pilihan pengguna dalam UTF-16, lalu dihapus setelah dibaca Word.
Jika dokumen aktif atau teksnya berubah selama pemilihan, penyisipan dibatalkan. Dokumen terkunci/hanya baca ditolak.
Pemasang tidak mengubah macro policy, Trusted Locations, Trust Access VBA, execution policy Windows, atau certificate store.
Jalankan hanya macro yang diizinkan kebijakan kantor. Jika PowerShell diblokir kebijakan kantor, gunakan proses instalasi yang disetujui administrator; jangan menonaktifkan kebijakan.
ID macro/shape dipertahankan agar dokumen lama tetap kompatibel. Sistem aktivasi desktop dan Word dihapus untuk edisi SIMANIS mandiri sesuai instruksi pengguna.
Fitur desktop dan garis Word langsung digunakan tanpa lisensi/aktivasi. Login SIMANIS tetap diperlukan hanya untuk mengakses data penghadap/transaksi sesuai hak akun.
Build desktop mandiri tidak membutuhkan atau menyertakan .env lisensi, secret, cache aktivasi, atau bridge lisensi. Paket desktop portable berisi SIMANIS.exe dan paket Word opsional.

## Backend yang disiapkan di repo SIMANIS

- GET /api/auth/word/clients?q=...
- GET /api/auth/word/clients/{id}
- GET /api/auth/word/ppat?q=...
- GET /api/auth/word/ppat/{work|book}/{id}
- DELETE /api/auth/word/session (hanya token saat ini)
Semua memakai auth:sanctum, throttle dan respons data no-store. Tidak membutuhkan perubahan skema.

Sebelum dipakai kantor, pasang WordDataController.php dan rute terkait pada server SIMANIS, siapkan HTTPS pada IP kantor beserta sertifikat IP SAN yang valid, lalu konfigurasi alamat API/CA di helper.
Jangan mengirim login ke port HTTP 8000. Belum ada perubahan/deploy server dalam pekerjaan ini.

## Hasil pemeriksaan

- 58 tes backend SIMANIS lulus, termasuk scope pemilik, pihak yang diwakili, role admin/Arsip, field allowlist dan autentikasi.
- 47 tes Python desktop/helper lulus, termasuk startup desktop dengan jaringan diblokir, tidak ada gerbang aktivasi pada Word, HTTPS, OTP, token header/session, Unicode dan baris baru.
- Builder memverifikasi source VBA paket identik, metadata/ribbon SIMANIS ada, tanpa handler auto-run.
- Template dibuka pada instance Word terpisah dengan macro dinonaktifkan, ditutup tanpa simpan; hash file tidak berubah.
- GUI helper berhasil dibentuk dan sintaks PowerShell pemasang diperiksa.
- Belum menguji login/data produksi atau menjalankan macro penyisipan secara end-to-end; server sengaja belum dipasang.


## Edisi mandiri 1.5.1 / Word 0.5.1

Atas instruksi pengguna, seluruh gerbang aktivasi desktop dan Word dilepas, termasuk pemeriksaan online, pembacaan cache/key, CLI bridge dan menu lisensi. Tidak membuat status aktivasi palsu atau key pengganti. Build tidak memuat .env/secret. Login SIMANIS tetap hanya untuk akses data penghadap/PPAT. Pengguna secara eksplisit menyetujui penggantian tes lisensi lama dengan tes edisi mandiri. 47 tes Python lulus, termasuk startup GUI desktop dengan akses jaringan diblokir dan tanpa cache aktivasi; tes memastikan kode Word tidak memiliki gerbang aktivasi. Paket desktop portable disiapkan dengan SIMANIS.exe dan Word opsional. Produksi/API/HTTPS server tetap tidak diubah.

## Lokasi aplikasi

Sumber desktop dan add-in Word berada di folder desktop/, sejajar backend/ dan frontend/. Kedua folder clone Radina telah dihapus setelah sumber dan build dipindahkan serta diverifikasi.

- Desktop portable: desktop/output/SIMANIS-Desktop-1.5.1-portable.zip
- Add-in Word: desktop/output/SIMANIS-Word-0.5.1-preview.zip
- Executable desktop: desktop/dist/SIMANIS-v1.5.1/SIMANIS.exe

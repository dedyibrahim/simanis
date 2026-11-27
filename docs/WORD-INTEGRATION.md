# SIMANIS Word 0.6.1 / Desktop 1.6.1

## Cara memakai

1. Simpan dokumen dan tutup semua jendela Word. Jalankan SIMANIS_Setup_v1.6.1.exe.
2. Buka Word > SIMANIS > Pekerjaan / Data Draf. Panel muncul di sebelah kanan dokumen; tidak membuka jendela helper terpisah.
3. Login akun SIMANIS dan masukkan OTP WhatsApp jika diminta. Alamat kantor sudah terisi: http://192.168.0.12:8000/api.
4. Biarkan Ingat login di akun Windows ini dicentang untuk memulihkan sesi setelah Word dibuka lagi. Password dan OTP tidak disimpan. Sesi yang diingat berlaku sampai 30 hari, sampai server menolak token, atau sampai pengguna keluar.
5. Cari dan pilih pekerjaan operasional. Centang field yang diperlukan, periksa pratinjau, lalu klik Masukkan ke posisi kursor. Panel tetap terbuka sehingga data dapat dimasukkan berulang kali.
6. Data masuk setelah kursor/seleksi pada dokumen yang aktif saat tombol ditekan. Teks yang terseleksi tetap ada; satu Ctrl+Z membatalkan satu penyisipan. Dokumen terkunci/hanya baca ditolak.
7. Klik Keluar untuk menghapus sesi Windows dan mencabut token sesi ini pada server. Jika offline, sesi lokal dihapus dan kegagalan pencabutan server ditampilkan.

## Sesi dan keamanan

Panel native COM menggunakan WinForms dan .NET Framework; tidak memerlukan VSTO atau browser. Installer mendaftarkan add-in hanya untuk akun Windows saat ini dan mendukung Office desktop Windows 32/64-bit.
Token login disimpan sebagai blob DPAPI CurrentUser di %LOCALAPPDATA%/SIMANIS/word-session.bin, terikat akun Windows dan komputer. Token tidak disimpan dalam dokumen, template, registry, log, JSON biasa atau argumen proses. Password dan OTP hanya dipakai saat login.
Menutup panel atau Word tidak mencabut sesi yang diingat. Keluar menghapus blob dan mencabut token sesi ini; sesi aplikasi SIMANIS lain tidak disentuh. Respons 401 membersihkan sesi dan meminta login ulang. File sesi rusak/kedaluwarsa dihapus. Alamat server termasuk di dalam blob terenkripsi sehingga token tidak diarahkan ke server lain.
Installer tidak mengubah kebijakan keamanan macro, Trusted Locations, certificate store, atau kebijakan eksekusi Windows. Template VBA tetap dipakai untuk ribbon dan fitur garis yang ada; tombol data memanggil COM add-in SIMANIS.Word.
HTTP hanya diperbolehkan untuk IP LAN privat atau loopback. HTTPS tetap memeriksa sertifikat. Tidak mengikuti redirect. Gunakan HTTP hanya pada LAN kantor tepercaya. API membatasi Asisten ke pekerjaan/nomor miliknya, Admin/Super Admin semua dan menolak Arsip.

## Data pekerjaan

Pekerjaan Saya berasal dari operasional kantor: Notaris, PPAT, Legalisasi dan Waarmerking. Daftar/detail mencakup pesanan, penghadap, kedudukan, pihak yang diwakili dan field transaksi PPAT yang relevan. API: GET /api/auth/word/jobs dan GET /api/auth/word/jobs/{id}. API penghadap dan PPAT lama tetap tersedia.
Ini penyisipan field teks untuk membantu menyusun draf. Tidak menghasilkan redaksi hukum atau mengganti placeholder template secara otomatis.
API produksi kedua node sudah tersedia melalui VIP kantor; tidak ada perubahan database atau deploy server yang diperlukan untuk versi panel ini.

## Build dan pemeriksaan

Build: python build_simanis_installer.py --iscc path/to/ISCC.exe (Inno Setup 6.4+). Native DLL: python word-pane/build.py; membutuhkan compiler .NET Framework dan Office/Extensibility PIA pada komputer build. Antarmuka interop di-embed sehingga komputer pengguna tidak membutuhkan instalasi PIA tambahan.
Tes sesi: python word-pane/test_session.py. Tes COM Word terisolasi: python word-pane/build.py --test lalu python word-pane/test_word_integration.py. Test memakai ProgID/GUID terpisah, dokumen dummy, data sintetis dan membersihkan registry test. Tidak memakai akun/data kantor.

## Verifikasi rilis

48 tes desktop/helper lulus. Tes sesi native memverifikasi DPAPI lintas proses, penggantian atomik, kedaluwarsa, kerusakan file, logout, pembatasan URL, login HTTP sintetis, respons 401 dan tidak mengikuti redirect. Panel diuji pada Word sungguhan dengan dokumen dummy: tampil di dalam Word, memasukkan data berulang kali saat panel data terbuka, satu Undo dan penolakan dokumen terkunci. Layout login dan data ditinjau dari snapshot test.
Installer EXE diuji untuk pemasangan pertama dan upgrade di folder terisolasi: hash desktop/helper/DLL/template sesuai, template lama dicadangkan, template lain dan registry pengguna tetap utuh. Versi 1.6.0 sebelumnya dipasang untuk akun Windows pengguna, dan COM add-in/panel yang terpasang berhasil dimuat di Word. Pembaruan narasi 1.6.1 tersedia melalui installer dan memerlukan Word/SIMANIS ditutup sebelum pemasangan. Tidak memakai password, OTP atau data kantor untuk tes.

## Narasi dan terbilang 1.6.1

Setelah memilih pekerjaan, pilih bentuk Narasi penghadap lalu pilih satu nama pada dropdown Penghadap. Hanya data orang tersebut yang ditawarkan; tidak memasukkan semua penghadap sekaligus. Pilih sapaan Sesuai nama/Tuan/Nyonya/Nona/Tanpa sapaan. Sesuai nama hanya memakai sapaan yang sudah ada dalam nama, tidak mengira status perkawinan. Narasi memuat data identitas yang tersedia, tanggal lahir angka/terbilang, pekerjaan, kewarganegaraan dan alamat. Nama dibuat tebal saat disisipkan. Nomor identitas dan nol di depan tetap dipertahankan; badan hukum tidak diberi sapaan pribadi atau disebut pemegang KTP.

Centang/hapus centang field yang diperlukan. Nama wajib untuk membentuk narasi. Data kosong tidak dikarang; tanggal tidak valid harus dikoreksi pada client atau dihilangkan dari pilihan. Kelurahan/Desa tidak disimpulkan jika jenisnya tidak tercatat. Kedudukan dan kewenangan mewakili tidak disusun menjadi pernyataan hukum otomatis. Field pilihan tersedia jika diperlukan untuk mengambil data mentah, termasuk pihak yang diwakili.

Pilih Transaksi PPAT untuk memilih nominal pekerjaan tersebut. Harga transaksi, NJOP, BPHTB dan PPh dapat diformat Rp125.000.000,00 (seratus dua puluh lima juta rupiah) dengan Sertakan terbilang nominal PPAT dicentang. Pecahan dua digit dibaca sebagai sen, bukan dibulatkan diam-diam; lebih dari dua digit bermakna ditolak. NIK dan nomor objek bukan nominal dan tidak diubah menjadi terbilang.

Pratinjau di bagian bawah bisa diedit sebelum Masukkan ke posisi kursor. Mengganti penghadap, mode atau centang field membentuk ulang pratinjau. Tidak menambahkan nomor urut penghadap atau judul metadata pada narasi. Hasil mengikuti font dokumen dan memberi huruf tebal hanya pada nama; satu Undo membatalkan sisipan beserta formatnya.

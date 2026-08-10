<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SimanisDemoSeeder extends Seeder
{
    private Carbon $now;
    private object $actor;

    public function run(): void
    {
        $this->now = Carbon::now();

        if (!Schema::hasTable('users')) {
            $this->command?->warn('Tabel users belum ada. Jalankan migration dulu.');
            return;
        }

        $this->ensureLegacyUsers();
        $this->actor = DB::table('users')->where('id_user', '0001')->first()
            ?? DB::table('users')->orderBy('id')->first();

        if (!$this->actor) {
            $this->command?->warn('User belum tersedia. Seeder demo dihentikan.');
            return;
        }

        $this->seedMasterData();
        $this->seedClientData();
        $this->seedReportorium();
        $this->seedOrdersAndInvoices();
        $this->seedSchedules();
        $this->seedPeminjamanMinuta();
        $this->seedScannedDocuments();
        $this->seedTandaTerima();
        $this->seedPpatRekanan();
        $this->seedOperationalSettings();
        $this->seedDocumentApprovals();
    }

    private function ensureLegacyUsers(): void
    {
        $users = [
            ['id_user' => '0001', 'username' => 'admin', 'nama_lengkap' => 'Admin', 'email' => 'dedy@notaris-jakarta.com', 'phone' => '0887487772', 'level_user' => 'Super Admin', 'password_plain' => 'admin@123', 'foto' => '5ebd6be3c03d1.png'],
            ['id_user' => '0002', 'username' => 'wisnu', 'nama_lengkap' => 'Wisnu Subroto N.A', 'email' => 'yuniaryanto679@gmail.com', 'phone' => '087877912311', 'level_user' => 'User', 'password_plain' => 'wisnu@123', 'foto' => '5df0a79c94e92.png'],
            ['id_user' => '0003', 'username' => 'dian', 'nama_lengkap' => 'Siti Rizki Dianti', 'email' => 'dian@notaris-jakarta.com', 'phone' => '085289885222', 'level_user' => 'User', 'password_plain' => 'dian@123', 'foto' => null],
            ['id_user' => '0004', 'username' => 'prima', 'nama_lengkap' => 'Prima Yuddy F Y', 'email' => 'prima@notaris-jakarta.com', 'phone' => '085263908704', 'level_user' => 'User', 'password_plain' => 'prima@123', 'foto' => null],
            ['id_user' => '0005', 'username' => 'dini', 'nama_lengkap' => 'Pratiwi S Dini', 'email' => 'dini@notaris-jakarta.com', 'phone' => '081273602067', 'level_user' => 'User', 'password_plain' => 'dini@123', 'foto' => null],
            ['id_user' => '0006', 'username' => 'rifka', 'nama_lengkap' => 'Rifka Ramadani', 'email' => 'rifka@notaris-jakarta.com', 'phone' => '087739397228', 'level_user' => 'User', 'password_plain' => 'rifka@123', 'foto' => null],
            ['id_user' => '0007', 'username' => 'yus', 'nama_lengkap' => 'Yus Suwandari', 'email' => 'yus@notaris-jakarta.com', 'phone' => '081280716583', 'level_user' => 'User', 'password_plain' => 'yyus@123', 'foto' => '5e6f20017aca7.png'],
            ['id_user' => '0008', 'username' => 'esthi', 'nama_lengkap' => 'Esthi Herlina', 'email' => 'esthi@notaris-jakarta.com', 'phone' => '081517697047', 'level_user' => 'User', 'password_plain' => 'esthi@123', 'foto' => '5d005f8da4b9d.png'],
            ['id_user' => '0010', 'username' => 'indy', 'nama_lengkap' => 'indarty', 'email' => 'indy@notaris-jakarta.com', 'phone' => '087876227696', 'level_user' => 'User', 'password_plain' => 'indy@123', 'foto' => null],
            ['id_user' => '0011', 'username' => 'fitri', 'nama_lengkap' => 'Fitri Senjayani', 'email' => 'fitri@notaris-jakarta.com', 'phone' => '08121923365', 'level_user' => 'User', 'password_plain' => 'fitri@123', 'foto' => null],
            ['id_user' => '0012', 'username' => 'fadzri', 'nama_lengkap' => 'MK Fadzri Patriajaya', 'email' => 'fadzri@notaris-jakarta.com', 'phone' => '087788105424', 'level_user' => 'User', 'password_plain' => 'fadzri@123', 'foto' => '5df2eead14666.png'],
            ['id_user' => '0013', 'username' => 'rohmad', 'nama_lengkap' => 'agus rohmad', 'email' => 'agusrohmad300@gmail.com', 'phone' => '081806446192', 'level_user' => 'User', 'password_plain' => 'rohmad@123', 'foto' => '5f336f8ddb31b.png'],
            ['id_user' => '0014', 'username' => 'admin2', 'nama_lengkap' => 'Dewantari Handayani SH.MPA', 'email' => 'dewantari@notaris-jakarta.com', 'phone' => '-', 'level_user' => 'Admin', 'password_plain' => 'admin2@123', 'foto' => null],
            ['id_user' => '0016', 'username' => 'imam', 'nama_lengkap' => 'Imam Syafii', 'email' => 'imamsyafii060179@gmail.com', 'phone' => '087878914988', 'level_user' => 'User', 'password_plain' => 'imam@123', 'foto' => null],
            ['id_user' => '0017', 'username' => 'Sastra', 'nama_lengkap' => 'Sastra Wardana', 'email' => 'sastrawardana@notaris-jakarta.com', 'phone' => '081292235391', 'level_user' => 'User', 'password_plain' => 'sastra@123', 'foto' => null],
            ['id_user' => '0018', 'username' => 'eka', 'nama_lengkap' => 'Eka Andriani', 'email' => 'eka@notaris-jakarta.com', 'phone' => '0', 'level_user' => 'User', 'password_plain' => 'eeka@123', 'foto' => null],
            ['id_user' => '0019', 'username' => 'arsip', 'nama_lengkap' => 'Anak Magang', 'email' => 'arsip@gmail.com', 'phone' => '081289903664', 'level_user' => 'User', 'password_plain' => 'arsip@123', 'foto' => null],
            ['id_user' => '0039', 'username' => 'amel', 'nama_lengkap' => 'amel', 'email' => 'amel@gmail.com', 'phone' => '-', 'level_user' => 'User', 'password_plain' => 'amel@123', 'foto' => null],
        ];

        foreach ($users as $user) {
            $existing = DB::table('users')
                ->where('id_user', $user['id_user'])
                ->orWhere('email', $user['email'])
                ->first();

            $payload = [
                'id_user' => $user['id_user'],
                'username' => $user['username'],
                'nama_lengkap' => $user['nama_lengkap'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'level_user' => $user['level_user'],
                'foto' => $user['foto'],
                'status' => true,
                'updated_at' => $this->now,
            ];

            if ($existing) {
                unset($payload['id_user'], $payload['email']);
                DB::table('users')->where('id', $existing->id)->update($payload);
                continue;
            }

            $payload['password'] = Hash::make($user['password_plain']);
            $payload['created_at'] = $this->now;
            DB::table('users')->insert($payload);
        }
    }

    private function seedMasterData(): void
    {
        if ($this->hasTable('daftar_aktas')) {
            $this->upsert('daftar_aktas', ['id_akta' => 'DAKT001'], [
                'pekerjaan_milik' => 'NOTARIS',
                'nama_akta' => 'Akta Pendirian Perseroan Terbatas',
                'apht' => 'FALSE',
            ]);
            $this->upsert('daftar_aktas', ['id_akta' => 'DAKT002'], [
                'pekerjaan_milik' => 'PPAT',
                'nama_akta' => 'Akta Jual Beli',
                'apht' => 'FALSE',
            ]);
        }

        if ($this->hasTable('tb_nama_dokumens')) {
            foreach ([
                'DDOC001' => 'KTP',
                'DDOC002' => 'Kartu Keluarga',
                'DDOC003' => 'NPWP',
                'DDOC004' => 'Sertifikat Hak Milik',
            ] as $id => $name) {
                $this->upsert('tb_nama_dokumens', ['id_dokumen' => $id], ['nama_dokumen' => $name]);
            }
        }
    }

    private function seedClientData(): void
    {
        if (!$this->hasTable('data_clients')) {
            return;
        }

        $clients = [
            ['id_client' => 'DCL001', 'no_identitas' => '3174010101800001', 'nama_client' => 'Budi Santoso', 'jenis_client' => 'Perorangan', 'alamat_client' => 'Jl. Melati No. 10, Jakarta Selatan', 'contact_number' => '081234560001', 'email' => 'budi.demo@example.test'],
            ['id_client' => 'DCL002', 'no_identitas' => '3174020202810002', 'nama_client' => 'Sari Wijaya', 'jenis_client' => 'Perorangan', 'alamat_client' => 'Jl. Anggrek No. 8, Jakarta Barat', 'contact_number' => '081234560002', 'email' => 'sari.demo@example.test'],
            ['id_client' => 'DCL003', 'no_identitas' => 'AHU-001122.DEMO', 'nama_client' => 'PT Demo Sejahtera Abadi', 'jenis_client' => 'Badan Hukum', 'alamat_client' => 'Gedung Simanis Lt. 3, Jakarta Pusat', 'contact_number' => '0215550101', 'email' => 'legal@demo-sejahtera.example.test'],
        ];

        foreach ($clients as $client) {
            $this->upsert('data_clients', ['id_client' => $client['id_client']], $client + [
                'pembuat_client' => $this->actor->id_user,
                'nama_folder' => strtolower($client['id_client'] . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', $client['nama_client'])),
            ]);
        }

        if ($this->hasTable('tb_berkas')) {
            $this->upsert('tb_berkas', ['id_berkas' => 'DBRK001'], [
                'id_client' => 'DCL001',
                'id_dokumen' => 'DDOC001',
                'id_user' => $this->actor->id_user,
                'nama_berkas' => 'demo-ktp-budi.pdf',
                'nama_dokumen' => 'KTP',
            ]);
            $this->upsert('tb_berkas', ['id_berkas' => 'DBRK002'], [
                'id_client' => 'DCL003',
                'id_dokumen' => 'DDOC003',
                'id_user' => $this->actor->id_user,
                'nama_berkas' => 'demo-npwp-pt-demo.pdf',
                'nama_dokumen' => 'NPWP',
            ]);
        }
    }

    private function seedReportorium(): void
    {
        if ($this->hasTable('buku_notaris')) {
            $this->upsert('buku_notaris', ['id_buku_notaris' => 'DBN001'], [
                'id_akta' => 'DAKT001',
                'id_user' => $this->actor->id_user,
                'status_akta' => 'Proses',
                'judul_pekerjaan' => 'Pendirian PT Demo Sejahtera Abadi',
                'no_akta' => '101',
                'nama_client' => 'PT Demo Sejahtera Abadi',
                'tgl_akta' => $this->now->copy()->subDays(4)->toDateString(),
                'tgl_signing' => $this->now->copy()->addDays(2),
                'created_by' => $this->actor->id_user,
            ]);
        }

        if ($this->hasTable('buku_legalisasis')) {
            $this->upsert('buku_legalisasis', ['id_buku_legalisasi' => 'DBL001'], [
                'no_legalisasi' => 'L-001/DEMO/VIII/2026',
                'tgl_surat' => $this->now->copy()->subDays(2)->toDateString(),
                'judul_surat' => 'Legalisasi Surat Kuasa',
                'nama_client' => 'Budi Santoso',
                'keterangan_surat' => 'Data demo legalisasi',
                'id_user' => $this->actor->id_user,
                'status_legalisasi' => 'Selesai',
            ]);
        }

        if ($this->hasTable('buku_warmerkings')) {
            $this->upsert('buku_warmerkings', ['id_buku_warmerking' => 'DBW001'], [
                'no_warmerking' => 'W-001/DEMO/VIII/2026',
                'tgl_surat' => $this->now->copy()->subDays(6)->toDateString(),
                'tgl_didaftarkan' => $this->now->copy()->subDays(5)->toDateString(),
                'judul_surat' => 'Waarmerking Perjanjian Kerja Sama',
                'nama_client' => 'Sari Wijaya',
                'keterangan_surat' => 'Data demo waarmerking',
                'id_user' => $this->actor->id_user,
                'status_warmerking' => 'Proses',
            ]);
        }

        if ($this->hasTable('buku_ppats')) {
            $this->upsert('buku_ppats', ['id_buku_ppat' => 'DBP001'], [
                'id_akta' => 'DAKT002',
                'id_user' => $this->actor->id_user,
                'status_akta' => 'Proses',
                'no_akta' => 88,
                'tanggal_akta' => $this->now->copy()->subDays(1)->toDateString(),
                'pihak_mengalihkan' => 'Budi Santoso',
                'pihak_menerima' => 'Sari Wijaya',
                'no_hak_milik' => 'SHM 1234/Demo',
                'luas_tanah_bangunan' => 120,
                'luas_tanah' => 90,
                'luas_bangunan' => 60,
                'harga_transaksi' => 'Rp 850.000.000',
                'nop' => '31.74.001.001.001-0001.0',
                'harga_njop' => 'Rp 700.000.000',
                'tgl_bphtb' => $this->now->copy()->toDateString(),
                'harga_bphtb' => 'Rp 42.500.000',
                'tgl_pph' => $this->now->copy()->toDateString(),
                'harga_pph' => 'Rp 21.250.000',
                'keterangan' => 'Data demo PPAT',
            ]);
        }

        if ($this->hasTable('buku_surat_notaris')) {
            $this->upsert('buku_surat_notaris', ['id_surat_notaris' => 'DSN001'], [
                'id_client' => 'DCL003',
                'no_surat' => 'SN-001/DEMO/VIII/2026',
                'keterangan' => 'Surat keterangan notaris demo',
                'pengirim' => $this->actor->id_user,
                'file' => null,
            ]);
        }

        if ($this->hasTable('buku_surat_ppats')) {
            $this->upsert('buku_surat_ppats', ['id_surat_ppat' => 'DSP001'], [
                'id_client' => 'DCL001',
                'no_surat' => 'SP-001/DEMO/VIII/2026',
                'keterangan' => 'Surat PPAT demo',
                'pengirim' => $this->actor->id_user,
                'file' => null,
            ]);
        }
    }

    private function seedOrdersAndInvoices(): void
    {
        if (!$this->hasTable('orders')) {
            return;
        }

        $this->upsert('orders', ['id_order' => 'DOR001'], [
            'nama_pesanan' => 'Pendirian PT Demo Sejahtera Abadi',
            'keterangan_order' => 'Order demo dengan invoice pajak',
            'id_user' => $this->actor->id_user,
            'no_inv' => 'INV-TAX-DEMO-001',
            'jenis_invoice' => 'Tax',
            'ket_noinv' => 'Demo',
            'status_order' => 'Proses',
        ]);

        $this->upsert('orders', ['id_order' => 'DOR002'], [
            'nama_pesanan' => 'Legalisasi Surat Kuasa Budi Santoso',
            'keterangan_order' => 'Order demo tanpa pajak',
            'id_user' => $this->actor->id_user,
            'no_inv' => 'INV-NT-DEMO-001',
            'jenis_invoice' => 'Non Tax',
            'ket_noinv' => 'Demo',
            'status_order' => 'Selesai',
        ]);

        if ($this->hasTable('detail_pesanans')) {
            $this->upsert('detail_pesanans', ['id_detail_pesanan' => 'DDP001'], [
                'id_order' => 'DOR001',
                'id_pekerjaan' => 'DBN001',
                'jenis_pekerjaan' => 'Notaris',
                'nama_pekerjaan' => 'Akta Pendirian PT',
                'no_pekerjaan' => '101',
                'pembuat' => $this->actor->id_user,
                'tanggal_pekerjaan' => $this->now->copy()->subDays(4)->toDateString(),
                'harga' => 3500000,
            ]);
            $this->upsert('detail_pesanans', ['id_detail_pesanan' => 'DDP002'], [
                'id_order' => 'DOR002',
                'id_pekerjaan' => 'DBL001',
                'jenis_pekerjaan' => 'Legalisasi',
                'nama_pekerjaan' => 'Legalisasi Surat Kuasa',
                'no_pekerjaan' => 'L-001/DEMO/VIII/2026',
                'pembuat' => $this->actor->id_user,
                'tanggal_pekerjaan' => $this->now->copy()->subDays(2)->toDateString(),
                'harga' => 250000,
            ]);
        }

        if ($this->hasTable('invoice_taxs')) {
            $this->upsert('invoice_taxs', ['id_invoice_tax' => 'DIT001'], [
                'id_order' => 'DOR001',
                'status_invoice' => 'Belum Lunas',
                'tax' => 385000,
                'status_diskon' => false,
                'status_tax' => true,
                'nilai_diskon' => 0,
                'diskon' => 0,
                'grand_total' => 3885000,
            ]);
        }

        if ($this->hasTable('invoice_non_taxs')) {
            $this->upsert('invoice_non_taxs', ['id_invoice_non_tax' => 'DNT001'], [
                'id_order' => 'DOR002',
                'status_invoice' => 'Lunas',
                'tax' => 0,
                'status_diskon' => false,
                'status_tax' => false,
                'nilai_diskon' => 0,
                'diskon' => 0,
                'grand_total' => 250000,
            ]);
        }

        if ($this->hasTable('dokumen_orders')) {
            $this->upsert('dokumen_orders', ['id_dokumen_order' => 'DDO001'], [
                'id_order' => 'DOR001',
                'id_dokumen' => 'DDOC001',
                'id_user' => $this->actor->id_user,
                'nama_berkas' => 'demo-order-ktp.pdf',
            ]);
        }
    }

    private function seedSchedules(): void
    {
        if (!$this->hasTable('events')) {
            return;
        }

        $this->upsert('events', ['title' => 'DEMO - Signing Akta Pendirian PT'], [
            'creator_id' => $this->actor->id,
            'description' => 'Agenda signing demo untuk modul jadwal.',
            'location' => 'Ruang Meeting 1',
            'start_datetime' => $this->now->copy()->addDays(2)->setTime(10, 0),
            'end_datetime' => $this->now->copy()->addDays(2)->setTime(11, 30),
            'color' => '#2563eb',
            'reminder_sent_at' => null,
        ]);

        if ($this->hasTable('event_user')) {
            $event = DB::table('events')->where('title', 'DEMO - Signing Akta Pendirian PT')->first();
            if ($event) {
                $this->upsert('event_user', ['event_id' => $event->id, 'user_id' => $this->actor->id], []);
            }
        }
    }

    private function seedPeminjamanMinuta(): void
    {
        if (!$this->hasTable('peminjaman_minutas')) {
            return;
        }

        $this->upsert('peminjaman_minutas', ['no_akta' => '101', 'nama_peminjam' => 'Budi Santoso'], [
            'no_bundle' => 'BND-DEMO-001',
            'keperluan' => 'Pengecekan minuta untuk salinan',
            'tanggal_pinjam' => $this->now->copy()->subDays(3)->toDateString(),
            'tanggal_kembali' => $this->now->copy()->addDays(4)->toDateString(),
            'status' => 'Dipinjam',
            'keterangan' => 'Data demo peminjaman minuta',
            'created_by' => $this->actor->id,
            'is_terlambat' => false,
            'jumlah_perpanjangan' => 0,
            'tanggal_perpanjangan_terakhir' => null,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function seedScannedDocuments(): void
    {
        if (!$this->hasTable('scan_sessions') || !$this->hasTable('scanned_documents')) {
            return;
        }

        $this->upsert('scan_sessions', ['token' => '11111111-2222-4333-8444-555555555555'], [
            'assistant_user_id' => $this->actor->id,
            'status' => 'uploaded',
            'output_type' => 'pdf',
            'note' => 'Sesi scan demo',
            'created_ip' => '127.0.0.1',
            'user_agent' => 'SimanisDemoSeeder',
            'expires_at' => $this->now->copy()->addDay(),
            'uploaded_at' => $this->now,
        ]);

        $session = DB::table('scan_sessions')->where('token', '11111111-2222-4333-8444-555555555555')->first();
        if ($session) {
            $this->upsert('scanned_documents', ['file_path' => 'storage/demo/scan-demo.pdf'], [
                'scan_session_id' => $session->id,
                'assistant_user_id' => $this->actor->id,
                'title' => 'DEMO - Scan KTP Client',
                'original_name' => 'scan-demo.pdf',
                'file_name' => 'scan-demo.pdf',
                'mime_type' => 'application/pdf',
                'extension' => 'pdf',
                'size_bytes' => 128000,
                'status' => 'posted',
                'note' => 'Dokumen scan demo',
                'uploaded_by_agent' => 'demo-seeder',
            ]);
        }
    }

    private function seedTandaTerima(): void
    {
        if (!$this->hasTable('tanda_terima')) {
            return;
        }

        $this->upsert('tanda_terima', ['nomor_tanda_terima' => 'TT-DEMO-001'], [
            'nama_pengirim' => 'Budi Santoso',
            'nama_penerima' => 'Admin',
            'up_penerima' => 'Bagian Arsip',
            'keterangan_tanda_terima' => 'Penyerahan dokumen demo',
            'pembuat' => $this->actor->id_user,
            'status' => 'masuk',
            'lokasi' => 'Meja Arsip',
            'file' => null,
        ]);

        if ($this->hasTable('isi_diterima')) {
            $receipt = DB::table('tanda_terima')->where('nomor_tanda_terima', 'TT-DEMO-001')->first();
            if ($receipt) {
                $this->upsert('isi_diterima', ['tanda_terima_id' => $receipt->id, 'isi_diterima' => 'KTP dan NPWP demo'], []);
            }
        }
    }

    private function seedPpatRekanan(): void
    {
        if (!$this->hasTable('ppat_rekanans')) {
            return;
        }

        $this->upsert('ppat_rekanans', ['nama_ppat' => 'PPAT Rekanan Demo'], [
            'alamat' => 'Jl. Rekanan No. 5, Jakarta',
            'no_hp' => '081234569999',
            'aktif' => true,
        ]);

        $rekanan = DB::table('ppat_rekanans')->where('nama_ppat', 'PPAT Rekanan Demo')->first();

        if ($rekanan && $this->hasTable('ppat_rekanan_kedalams')) {
            $this->upsert('ppat_rekanan_kedalams', ['ppat_rekanan_id' => $rekanan->id, 'no_akta' => 'R-77/DEMO/2026'], [
                'tanggal_akta' => $this->now->copy()->subDays(7)->toDateString(),
                'id_akta' => 'DAKT002',
                'nama_akta_manual' => null,
                'pihak_mengalihkan' => 'Demo Penjual',
                'pihak_menerima' => 'Demo Pembeli',
                'no_hak_milik' => 'SHM 9988/Demo',
                'luas_tanah' => 100,
                'luas_bangunan' => 80,
                'harga_transaksi' => 'Rp 950.000.000',
                'nop' => '31.74.001.002.002-0002.0',
                'harga_njop' => 'Rp 800.000.000',
                'tgl_bphtb' => $this->now->copy()->subDays(6)->toDateString(),
                'harga_bphtb' => 'Rp 47.500.000',
                'tgl_pph' => $this->now->copy()->subDays(6)->toDateString(),
                'harga_pph' => 'Rp 23.750.000',
                'keterangan' => 'Akta PPAT rekanan demo',
                'created_by' => $this->actor->id_user,
            ]);
        }

        if ($rekanan && $this->hasTable('buku_ppats') && Schema::hasColumn('buku_ppats', 'ppat_rekanan_keluar_id')) {
            DB::table('buku_ppats')->where('id_buku_ppat', 'DBP001')->update([
                'ppat_rekanan_keluar_id' => $rekanan->id,
                'rekanan_keluar_catatan' => 'Nomor demo dipakai PPAT rekanan',
                'rekanan_keluar_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedOperationalSettings(): void
    {
        if ($this->hasTable('report_settings')) {
            $this->upsert('report_settings', ['name' => 'default'], [
                'header_label' => 'KANTOR NOTARIS / PPAT',
                'office_name' => 'Kantor Demo SIMANIS',
                'office_address' => 'Jl. Demo Raya No. 1, Jakarta',
                'office_email' => 'admin@simanis-demo.example.test',
                'office_phone' => '021-555-0001',
                'office_city' => 'Jakarta',
                'signatory_title' => 'Notaris / PPAT DKI Jakarta',
                'signatory_name' => 'Dewantari Handayani SH.MPA',
                'invoice_bank_account_1' => 'BCA 0000000000 a.n. Kantor Demo SIMANIS',
                'invoice_bank_account_2' => 'Mandiri 1111111111 a.n. Kantor Demo SIMANIS',
                'invoice_bank_account_3' => null,
            ]);
        }

        if ($this->hasTable('waha_configs')) {
            $this->upsert('waha_configs', ['name' => 'default'], [
                'base_url' => env('WAHA_BASE_URL', 'http://waha:3000'),
                'api_key' => env('WAHA_API_KEY'),
                'send_message_endpoint' => '/api/sendText',
                'check_number_endpoint' => '/api/contacts/check-exists',
                'status_endpoint' => '/api/sessions/default',
                'timeout_seconds' => 30,
                'enabled' => true,
                'metadata' => json_encode(['source' => 'demo-seeder']),
            ]);
        }
    }

    private function seedDocumentApprovals(): void
    {
        if (!$this->hasTable('document_download_requests')) {
            return;
        }

        $this->upsert('document_download_requests', [
            'module_path' => '/dokumen-scan',
            'row_id' => 'storage/demo/scan-demo.pdf',
            'file_name' => 'scan-demo.pdf',
        ], [
            'batch_id' => 'DEMO-BATCH-001',
            'batch_label' => 'Permintaan dokumen demo',
            'file_category' => 'scan',
            'requested_by_id_user' => $this->actor->id_user,
            'requested_by_name' => $this->actor->nama_lengkap,
            'status' => 'pending',
            'approved_by_id_user' => null,
            'approved_by_name' => null,
            'approved_at' => null,
            'note' => 'Permintaan approval dokumen demo',
        ]);
    }

    private function upsert(string $table, array $keys, array $values): void
    {
        $payload = array_merge($values, ['updated_at' => $this->now]);

        if (!$this->exists($table, $keys)) {
            $payload['created_at'] = $this->now;
        }

        foreach (array_keys($payload) as $column) {
            if (!Schema::hasColumn($table, $column)) {
                unset($payload[$column]);
            }
        }

        DB::table($table)->updateOrInsert($keys, $payload);
    }

    private function exists(string $table, array $keys): bool
    {
        $query = DB::table($table);
        foreach ($keys as $column => $value) {
            $query->where($column, $value);
        }

        return $query->exists();
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }
}

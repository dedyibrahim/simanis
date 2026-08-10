<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LocalBulkFakerSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalBulkFakerSeeder hanya boleh dijalankan di environment local/testing.');
        }

        if (!Schema::hasTable('data_clients')) {
            throw new RuntimeException('Tabel data_clients belum tersedia. Jalankan migration terlebih dahulu.');
        }

        $count = max(1, min((int) env('LOCAL_FAKE_CLIENTS', 1200), 10000));
        $actorId = DB::table('users')->where('id_user', '0001')->value('id_user');

        if (!$actorId) {
            throw new RuntimeException('User lokal 0001 belum tersedia. Jalankan SimanisDemoSeeder terlebih dahulu.');
        }

        $firstNames = ['Adi', 'Aisyah', 'Andi', 'Bambang', 'Citra', 'Dewi', 'Dimas', 'Fajar', 'Farah', 'Hendra', 'Indah', 'Joko', 'Lestari', 'Maya', 'Nadia', 'Putra', 'Rani', 'Rizky', 'Sari', 'Surya', 'Taufik', 'Wahyu', 'Yuni'];
        $lastNames = ['Adinata', 'Anggraini', 'Firmansyah', 'Gunawan', 'Halim', 'Hartono', 'Hidayat', 'Kurniawan', 'Kusuma', 'Maulana', 'Nugraha', 'Permana', 'Pratama', 'Ramadhan', 'Santoso', 'Saputra', 'Setiawan', 'Utami', 'Wijaya'];
        $cities = ['Jakarta Selatan', 'Jakarta Barat', 'Jakarta Timur', 'Jakarta Utara', 'Jakarta Pusat', 'Tangerang', 'Bekasi', 'Depok', 'Bogor'];
        $streets = ['Melati', 'Mawar', 'Anggrek', 'Kenanga', 'Cendana', 'Merdeka', 'Sudirman', 'Diponegoro', 'Pahlawan', 'Kemang'];
        $companyTypes = ['PT', 'CV', 'Yayasan', 'Koperasi'];
        $companyWords = ['Arunika', 'Berkah', 'Cakrawala', 'Digital', 'Gemilang', 'Karya', 'Mandiri', 'Nusantara', 'Prima', 'Sentosa'];
        $now = now();
        $clientRows = [];
        $documentRows = [];

        for ($i = 1; $i <= $count; $i++) {
            $number = 900000 + $i;
            $id = 'C' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $isCompany = $i % 4 === 0;
            $name = $isCompany
                ? $companyTypes[$i % count($companyTypes)] . ' ' . $companyWords[$i % count($companyWords)] . ' ' . $companyWords[($i * 3) % count($companyWords)]
                : $firstNames[$i % count($firstNames)] . ' ' . $lastNames[($i * 7) % count($lastNames)];
            $identity = $isCompany
                ? sprintf('AHU-%06d.LOCAL.%d', $i, 2020 + ($i % 7))
                : sprintf('3174%02d%02d%02d%06d', ($i % 12) + 1, ($i % 28) + 1, ($i % 30) + 70, $i);

            $clientRows[] = [
                'id_client' => $id,
                'no_identitas' => $identity,
                'nama_client' => $name,
                'jenis_client' => $isCompany ? 'Badan Hukum' : 'Perorangan',
                'alamat_client' => sprintf('Jl. %s No. %d, %s', $streets[$i % count($streets)], ($i % 180) + 1, $cities[$i % count($cities)]),
                'pembuat_client' => $actorId,
                'nama_folder' => $id,
                'contact_number' => '08' . str_pad((string) (1100000000 + $i), 10, '0', STR_PAD_LEFT),
                'email' => sprintf('client%04d@local.simanis.test', $i),
                'created_at' => $now->copy()->subDays($i % 365)->addMinutes($i % 1440),
                'updated_at' => $now,
            ];

            if (Schema::hasTable('tb_berkas')) {
                foreach ([1 => ['DDOC001', 'KTP'], 2 => [$isCompany ? 'DDOC003' : 'DDOC002', $isCompany ? 'NPWP' : 'Kartu Keluarga']] as $slot => [$documentId, $documentName]) {
                    $documentRows[] = [
                        'id_berkas' => sprintf('L%06d%d', $i, $slot),
                        'id_client' => $id,
                        'id_dokumen' => $documentId,
                        'id_user' => $actorId,
                        'nama_berkas' => sprintf('local-%06d-%d.pdf', $i, $slot),
                        'nama_dokumen' => $documentName,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (count($clientRows) === 250 || $i === $count) {
                DB::table('data_clients')->upsert($clientRows, ['id_client'], [
                    'no_identitas', 'nama_client', 'jenis_client', 'alamat_client', 'pembuat_client',
                    'nama_folder', 'contact_number', 'email', 'created_at', 'updated_at',
                ]);
                $clientRows = [];
            }

            if (count($documentRows) >= 500 || ($i === $count && $documentRows)) {
                DB::table('tb_berkas')->upsert($documentRows, ['id_berkas'], [
                    'id_client', 'id_dokumen', 'id_user', 'nama_berkas', 'nama_dokumen', 'updated_at',
                ]);
                $documentRows = [];
            }
        }

        if (Schema::hasTable('number_counters')) {
            DB::table('number_counters')->updateOrInsert(
                ['key_name' => 'client'],
                ['last_number' => 900000 + $count, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $this->command?->info("Faker lokal selesai: {$count} client dan " . ($count * 2) . ' metadata dokumen.');
    }
}

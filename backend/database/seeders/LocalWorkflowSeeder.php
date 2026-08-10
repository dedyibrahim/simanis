<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LocalWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalWorkflowSeeder hanya boleh dijalankan di environment local/testing.');
        }

        if (!Schema::hasTable('work_items')) {
            throw new RuntimeException('Tabel workflow belum tersedia. Jalankan migration terlebih dahulu.');
        }

        $clients = DB::table('data_clients')->orderByDesc('id_client')->limit(48)->pluck('id_client')->values();
        $users = DB::table('users')->orderBy('id_user')->pluck('id_user')->values();
        if ($clients->isEmpty() || $users->isEmpty()) {
            throw new RuntimeException('Data client dan user lokal harus tersedia sebelum membuat faker workflow.');
        }

        $statuses = ['draft', 'verification', 'in_progress', 'waiting_client', 'ready_to_sign', 'completed', 'ready_to_bill'];
        $titles = [
            'Pendirian Perseroan Terbatas', 'Perubahan Anggaran Dasar', 'Akta Jual Beli',
            'Legalisasi Perjanjian Kerja Sama', 'Waarmerking Perjanjian', 'Pendirian Yayasan',
            'Perubahan Susunan Direksi', 'Pelepasan Hak Atas Tanah', 'Perjanjian Kredit',
            'Berita Acara Rapat Umum Pemegang Saham', 'Surat Kuasa Membebankan Hak Tanggungan',
            'Pendirian Persekutuan Komanditer',
        ];
        $checklist = ['Dokumen identitas lengkap', 'Draft diperiksa asisten', 'Konfirmasi data kepada client', 'Persetujuan redaksi final'];
        $now = now();

        DB::transaction(function () use ($clients, $users, $statuses, $titles, $checklist, $now) {
            for ($i = 1; $i <= 42; $i++) {
                $status = $statuses[($i - 1) % count($statuses)];
                $number = 'WRK-LOCAL-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                $id = DB::table('work_items')->where('work_number', $number)->value('id');
                $payload = [
                    'title' => $titles[($i - 1) % count($titles)],
                    'category' => $i % 3 === 0 ? 'custom' : ($i % 2 === 0 ? 'mixed' : 'reportorium'),
                    'client_id' => $clients[($i - 1) % $clients->count()],
                    'assignee_id' => $users[$i % $users->count()],
                    'created_by' => $users[0],
                    'status' => $status,
                    'priority' => ['normal', 'high', 'urgent', 'low'][$i % 4],
                    'billing_status' => $status === 'ready_to_bill' ? 'ready' : 'not_ready',
                    'due_date' => $now->copy()->addDays(($i % 18) - 4)->toDateString(),
                    'notes' => 'Data simulasi workflow lokal untuk pengujian alur pekerjaan.',
                    'updated_at' => $now,
                ];

                if ($id) {
                    DB::table('work_items')->where('id', $id)->update($payload);
                } else {
                    $id = DB::table('work_items')->insertGetId($payload + [
                        'work_number' => $number,
                        'created_at' => $now->copy()->subDays($i % 21),
                    ]);
                }

                DB::table('work_item_checklists')->where('work_item_id', $id)->delete();
                foreach ($checklist as $position => $title) {
                    $completed = $position < array_search($status, $statuses, true);
                    DB::table('work_item_checklists')->insert([
                        'work_item_id' => $id, 'title' => $title, 'is_required' => true,
                        'is_completed' => $completed, 'completed_by' => $completed ? $users[0] : null,
                        'completed_at' => $completed ? $now->copy()->subDays(1) : null,
                        'sort_order' => $position + 1, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                DB::table('work_item_costs')->where('work_item_id', $id)->delete();

                DB::table('work_item_links')->where('work_item_id', $id)->where('module_type', 'custom')->delete();
                DB::table('work_item_links')->insert([
                    'work_item_id' => $id, 'module_type' => 'custom', 'record_id' => null,
                    'relationship_type' => 'output', 'label' => 'Draft dan dokumen pendukung pekerjaan',
                    'metadata' => json_encode(['source' => 'local-seeder']), 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('work_item_costs')->insert([
                    'work_item_id' => $id, 'description' => 'Draft dan dokumen pendukung pekerjaan',
                    'quantity' => 1, 'unit_price' => 0, 'taxable' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                if (!DB::table('work_item_activities')->where('work_item_id', $id)->where('event_type', 'seeded')->exists()) {
                    DB::table('work_item_activities')->insert([
                        'work_item_id' => $id, 'event_type' => 'seeded', 'actor_id' => $users[0],
                        'from_status' => null, 'to_status' => $status,
                        'description' => 'Pekerjaan simulasi dibuat untuk pengujian lokal.',
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });

        $this->command?->info('Faker workflow lokal selesai: 42 pekerjaan pada seluruh tahap pra-invoice.');
    }
}

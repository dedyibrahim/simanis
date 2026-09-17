<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('data_clients')
            ->where('jenis_client', 'Badan Hukum')
            ->where('has_npwp', true)
            ->where(function ($query) {
                $query->whereNull('no_identitas')
                    ->orWhereRaw("no_identitas NOT REGEXP '^[0-9]{15,16}$'");
            })
            ->update([
                'no_identitas' => null,
                'has_npwp' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Nilai lama tersedia pada backup audit; rollback otomatis berisiko menimpa koreksi manual.
    }
};

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
            ->whereRaw("no_identitas REGEXP '^9{5,}[0-9]*$'")
            ->update([
                'no_identitas' => null,
                'has_npwp' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Nilai asal dipertahankan pada backup audit produksi.
    }
};

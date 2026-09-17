<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_clients', function (Blueprint $table) {
            $table->boolean('has_npwp')->default(true)->after('no_identitas');
        });

        DB::statement('ALTER TABLE data_clients MODIFY no_identitas VARCHAR(255) NULL');

        DB::table('data_clients')
            ->where('jenis_client', 'Badan Hukum')
            ->where(function ($query) {
                $query->whereNull('no_identitas')
                    ->orWhereRaw("TRIM(no_identitas) = ''")
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(TRIM(no_identitas), '.', ''), '-', ''), ' ', '') REGEXP '^0{5,}[0-9]{0,7}$'")
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(TRIM(no_identitas), '.', ''), '-', ''), ' ', '') REGEXP '^0+$'");
            })
            ->update(['no_identitas' => null, 'has_npwp' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('data_clients')->whereNull('no_identitas')
            ->update(['no_identitas' => DB::raw("CONCAT('TANPANPWP', id_client)")]);
        DB::statement('ALTER TABLE data_clients MODIFY no_identitas VARCHAR(255) NOT NULL');
        Schema::table('data_clients', function (Blueprint $table) {
            $table->dropColumn('has_npwp');
        });
    }
};

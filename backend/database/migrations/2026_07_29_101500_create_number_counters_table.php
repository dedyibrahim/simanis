<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('number_counters')) {
            Schema::create('number_counters', function (Blueprint $table) {
                $table->string('key_name', 50)->primary();
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        $maxClientNumber = DB::table('data_clients')
            ->selectRaw("MAX(CAST(SUBSTRING(id_client, 2) AS UNSIGNED)) as max_number")
            ->value('max_number');

        DB::table('number_counters')->updateOrInsert(
            ['key_name' => 'client'],
            [
                'last_number' => max((int) ($maxClientNumber ?? 0), (int) (DB::table('number_counters')->where('key_name', 'client')->value('last_number') ?? 0)),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('number_counters');
    }
};

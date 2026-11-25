<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_clients', function (Blueprint $table) {
            $table->json('ktp_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('data_clients', function (Blueprint $table) {
            $table->dropColumn('ktp_data');
        });
    }
};

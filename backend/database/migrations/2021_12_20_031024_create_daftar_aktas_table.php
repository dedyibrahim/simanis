<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDaftarAktasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('daftar_aktas', function (Blueprint $table) {
            $table->string('id_akta', 15)->primary();
            $table->string('pekerjaan_milik', 10);
            $table->string('nama_akta', 150);
            $table->enum('apht', ['FALSE', 'TRUE'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('daftar_aktas');
    }
}

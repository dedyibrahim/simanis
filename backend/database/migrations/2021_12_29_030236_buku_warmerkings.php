<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class BukuWarmerkings extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */

    public function up()
    {
        Schema::create('buku_warmerkings', function (Blueprint $table) {
            $table->string("id_buku_warmerking",15)->unique()->primary();

            $table->string("no_warmerking");
            $table->date('tgl_surat')->nullable();
            $table->date('tgl_didaftarkan');

            $table->string('judul_surat',255);
            $table->text('nama_client')->nullable();
            $table->string('keterangan_surat',255)->nullable(true);

            $table->string('id_user',15);
            $table->foreign('id_user')->references('id_user')->on('users');

            $table->enum("status_warmerking",array('Lama','Proses','Selesai'));

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
        Schema::dropIfExists('buku_warmerkings');    //
    }
}

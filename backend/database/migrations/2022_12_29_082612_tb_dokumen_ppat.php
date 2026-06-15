<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tb_dokumen_ppat',function(Blueprint $table){
            $table->string('id_dokumen_ppat',15)->primary();

            $table->string('id_buku_ppat',15);
            $table->foreign('id_buku_ppat')->references('id_buku_ppat')->on('buku_ppats');

            $table->string('id_dokumen',15)->nullable();
            $table->foreign('id_dokumen')->references('id_dokumen')->on('tb_nama_dokumens');

            $table->string('id_user',15);
            $table->foreign('id_user')->references('id_user')->on('users');

            $table->string('nama_berkas');
            $table->string('nama_dokumen');
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
        //
    }
};

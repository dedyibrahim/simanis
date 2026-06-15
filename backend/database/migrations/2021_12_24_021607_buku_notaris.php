<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Enum;

class BukuNotaris extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('buku_notaris', function (Blueprint $table) {
            $table->string("id_buku_notaris",15)->unique()->primary();

            $table->string('id_akta',15)->nullable();
            $table->foreign('id_akta')->references('id_akta')->on('daftar_aktas');

            $table->string('id_user',15);
            $table->foreign('id_user')->references('id_user')->on('users');

            $table->enum("status_akta",array('Proses','Selesai','Lama'));
            $table->text("judul_pekerjaan")->nullable();
            $table->string("no_akta");
            $table->text('nama_client')->nullable();

            $table->date("tgl_akta");
            $table->dateTime("tgl_signing")->nullable();

            $table->string('created_by',15);
            $table->foreign('created_by')->references('id_user')->on('users');

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

        Schema::dropIfExists('buku_notaris');    //
    }
}

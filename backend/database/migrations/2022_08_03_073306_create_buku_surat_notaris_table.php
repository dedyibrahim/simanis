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
        Schema::create('buku_surat_notaris', function (Blueprint $table) {
            $table->string('id_surat_notaris',15)->primary();

            $table->string('id_client')->nullable();
            $table->foreign('id_client')->references('id_client')->on('data_clients');

            $table->string("no_surat");
            $table->text("keterangan")->nullable();


            $table->string('pengirim',15)->nullable();
            $table->foreign('pengirim')->references('id_user')->on('users');

            $table->string("file")->nullable();

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
        Schema::dropIfExists('buku_surat_notaris');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tanda_terima', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_tanda_terima');
            $table->string('nama_pengirim');
            $table->string('nama_penerima');
            $table->string('up_penerima');
            $table->string('keterangan_tanda_terima');
            $table->string('pembuat', 15);
            $table->foreign('pembuat')->references('id_user')->on('users');
            $table->enum('status', ['masuk', 'keluar']);
            $table->string('lokasi');

            $table->string('file')->nullable();
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
        Schema::dropIfExists('tanda_terima');
    }
};

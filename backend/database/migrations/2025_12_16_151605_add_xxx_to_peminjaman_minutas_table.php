<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up()
{
    Schema::create('peminjaman_minutas', function (Blueprint $table) {
        $table->id();

        $table->string('no_akta');
        $table->string('no_bundle')->nullable();
        $table->string('nama_peminjam');
        $table->string('keperluan')->nullable();

        $table->date('tanggal_pinjam');
        $table->date('tanggal_kembali');

        $table->enum('status', ['Dipinjam', 'Terlambat', 'Dikembalikan'])
              ->default('Dipinjam');

        $table->text('keterangan')->nullable();

        $table->unsignedBigInteger('created_by')->nullable();
        $table->timestamps();
    });
}

public function down()
{
    Schema::dropIfExists('peminjaman_minutas');
}

};

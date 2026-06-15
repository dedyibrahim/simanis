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
        Schema::create('detail_pesanans', function (Blueprint $table) {
            $table->string('id_detail_pesanan',15)->primary();
            $table->string('id_order',15)->nullable();
            $table->foreign('id_order')->references('id_order')->on('orders');
            $table->string('id_pekerjaan',15);
            $table->string('jenis_pekerjaan');
            $table->string('nama_pekerjaan');
            $table->string('no_pekerjaan')->nullable();
            $table->string('pembuat');
            $table->date('tanggal_pekerjaan');
            $table->bigInteger('harga')->nullable();
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
        Schema::dropIfExists('detail_pesanans');
    }
};

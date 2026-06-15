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
        Schema::create('orders', function (Blueprint $table) {
            $table->string('id_order',15)->primary();

            $table->text('nama_pesanan');

            $table->text('keterangan_order');

            $table->string('id_user',15)->nullable();
            $table->foreign('id_user')->references('id_user')->on('users');
            $table->string('no_inv')->nullable();
            $table->string('jenis_invoice')->nullable();
            $table->string('ket_noinv')->nullable();

            $table->enum('status_order',array('Proses','Selesai','Cancel'));
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
        Schema::dropIfExists('orders');
    }
};

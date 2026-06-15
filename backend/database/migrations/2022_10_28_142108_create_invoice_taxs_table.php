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
        Schema::create('invoice_taxs', function (Blueprint $table) {
            $table->string('id_invoice_tax',15)->primary();
            $table->string('id_order',15)->nullable();
            $table->foreign('id_order')->references('id_order')->on('orders');
            $table->enum('status_invoice',array('Belum Bayar','Belum Lunas','Lunas','Cancel'));
            $table->bigInteger('tax');
            $table->boolean('status_diskon');
            $table->boolean('status_tax');
            $table->bigInteger('nilai_diskon');
            $table->bigInteger('diskon');
            $table->bigInteger('grand_total');
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
        Schema::dropIfExists('invoice_taxs');
    }
};

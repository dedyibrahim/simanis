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
        Schema::create('isi_diterima', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tanda_terima_id');
            $table->text('isi_diterima');
            $table->timestamps();

            $table->foreign('tanda_terima_id')->references('id')->on('tanda_terima')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('isi_diterima');
    }
};

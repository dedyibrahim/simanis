<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PenghadapLegalisasi extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('penghadap_legalisasis', function (Blueprint $table) {
            $table->string('id_penghadap_legalisasi',15)->primary();
            $table->string('id_client');
            $table->foreign('id_client')->references('id_client')->on('data_clients');
            $table->string('id_buku_legalisasi');
            $table->foreign('id_buku_legalisasi')->references('id_buku_legalisasi')->on('buku_legalisasis');
            $table->string('id_mewakili')->nullable();
            $table->foreign('id_mewakili')->references('id_client')->on('data_clients');
            $table->string("kedudukan",50);
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
        Schema::dropIfExists('penghadap_legalisasis');    //

    }
}

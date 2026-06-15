<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PenghadapPPATS extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('penghadap_ppats', function (Blueprint $table) {
            $table->string('id_penghadap_ppat',15)->primary();
            $table->string('id_client');
            $table->foreign('id_client')->references('id_client')->on('data_clients');
            $table->string('id_buku_ppat');
            $table->foreign('id_buku_ppat')->references('id_buku_ppat')->on('buku_ppats');
            $table->string('id_mewakili')->nullable();
            $table->foreign('id_mewakili')->references('id_client')->on('data_clients');
             $table->string("status_kedudukan",50);
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
        Schema::dropIfExists('penghadap_ppats');    //
    }
}

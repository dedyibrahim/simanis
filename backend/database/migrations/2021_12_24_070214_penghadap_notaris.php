<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PenghadapNotaris extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('penghadap_notaris', function (Blueprint $table) {
            $table->string('id_penghadap_notaris',15)->primary();
            $table->string('id_client');
            $table->foreign('id_client')->references('id_client')->on('data_clients');
            $table->string('id_buku_notaris');
            $table->foreign('id_buku_notaris')->references('id_buku_notaris')->on('buku_notaris');
            $table->string('id_mewakili')->nullable();
            $table->foreign('id_mewakili')->references('id_client')->on('data_clients');
            $table->string("kedudukan")->nullable();
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
        Schema::dropIfExists('penghadap_notaris');    //
    }
}

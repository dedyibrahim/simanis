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
        Schema::create('penyimpanan_bantek', function (Blueprint $table) {
            $table->increments('id');
           
            $table->string("no_bantek");
            
            $table->string('id_client',15);
            $table->foreign('id_client')->references('id_client')->on('data_clients');

            $table->string("lokasi_bantek")->nullable();
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
    }
};

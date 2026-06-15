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
        Schema::create('jadwal_notaris', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->dateTime('start');
            $table->dateTime('end');
            $table->string('jenis');
            $table->string('created_by',15);
            $table->foreign('created_by')->references('id_user')->on('users');
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
        Schema::dropIfExists('jadwal_notaris');
    }
};

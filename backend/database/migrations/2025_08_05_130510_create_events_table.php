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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Menggantikan 'name'/'summary'
            $table->text('description')->nullable();
            $table->string('location')->nullable(); // Menggantikan 'jenis'
            $table->dateTime('start_datetime'); // Menggantikan 'start'
            $table->dateTime('end_datetime'); // Menggantikan 'end'
            $table->string('color')->nullable();
            $table->timestamps(); // Kolom created_at dan updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('events');
    }
};

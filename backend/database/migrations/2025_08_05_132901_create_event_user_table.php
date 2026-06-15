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
        Schema::create('event_user', function (Blueprint $table) {
            $table->id();
            // Foreign key ke tabel events
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            // Foreign key ke tabel users
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Opsional: untuk memastikan satu user tidak bisa ditambahkan dua kali ke event yang sama
            // $table->unique(['event_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('event_user');
    }
};

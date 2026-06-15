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
         Schema::table('events', function (Blueprint $table) {

            // Inilah baris intinya:
            $table->foreignId('creator_id')      // Membuat kolom BIGINT(20) UNSIGNED bernama 'creator_id'
                  ->nullable()                   // Kolom ini BOLEH kosong (NULL)
                  ->after('id')                  // (Opsional) Menempatkan kolom ini setelah kolom 'id' agar rapi
                  ->constrained('users')         // Membuat FOREIGN KEY yang merujuk ke kolom 'id' di tabel 'users'
                  ->onDelete('set null');        // Jika user pembuat dihapus, isi kolom ini menjadi NULL, jangan hapus eventnya
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('events', function (Blueprint $table) {
            // Ini adalah kebalikan dari proses 'up()'
            $table->dropForeign(['creator_id']); // Hapus dulu foreign key constraint
            $table->dropColumn('creator_id');   // Baru hapus kolomnya
        });
    }
};

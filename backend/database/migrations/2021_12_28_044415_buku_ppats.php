<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class BukuPPATS extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('buku_ppats', function (Blueprint $table) {
            $table->string("id_buku_ppat",15)->unique()->primary();

            $table->string('id_akta',15)->nullable();
            $table->foreign('id_akta')->references('id_akta')->on('daftar_aktas');

            $table->string('id_user',15);
            $table->foreign('id_user')->references('id_user')->on('users');
            $table->enum("status_akta",array('Proses','Selesai','Lama'));

            $table->decimal("no_akta",8,0);
            $table->date("tanggal_akta");
            $table->text("pihak_mengalihkan")->nullable();
            $table->text("pihak_menerima")->nullable();
            $table->string("no_hak_milik")->nullable();
            $table->decimal("luas_tanah_bangunan",8,0)->nullable();
            $table->decimal("luas_tanah",8,0)->nullable();
            $table->decimal("luas_bangunan",8,0)->nullable();
            $table->text("harga_transaksi")->nullable();
            $table->string("nop")->nullable();
            $table->text("harga_njop")->nullable();
            $table->date("tgl_bphtb")->nullable();
            $table->text("harga_bphtb")->nullable();
            $table->date("tgl_pph")->nullable();
            $table->text("harga_pph")->nullable();
            $table->text("keterangan")->nullable();
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
        Schema::dropIfExists('buku_ppats');
    }
}

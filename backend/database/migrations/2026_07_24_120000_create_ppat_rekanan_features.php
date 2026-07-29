<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePpatRekananFeatures extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('ppat_rekanans')) {
            Schema::create('ppat_rekanans', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('nama_ppat');
                $table->text('alamat')->nullable();
                $table->string('no_hp', 40)->nullable();
                $table->boolean('aktif')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('buku_ppats')) {
            Schema::table('buku_ppats', function (Blueprint $table) {
                if (!Schema::hasColumn('buku_ppats', 'ppat_rekanan_keluar_id')) {
                    $table->unsignedBigInteger('ppat_rekanan_keluar_id')->nullable()->after('keterangan');
                }
                if (!Schema::hasColumn('buku_ppats', 'rekanan_keluar_catatan')) {
                    $table->text('rekanan_keluar_catatan')->nullable()->after('ppat_rekanan_keluar_id');
                }
                if (!Schema::hasColumn('buku_ppats', 'rekanan_keluar_at')) {
                    $table->timestamp('rekanan_keluar_at')->nullable()->after('rekanan_keluar_catatan');
                }
            });
        }

        if (!Schema::hasTable('ppat_rekanan_kedalams')) {
            Schema::create('ppat_rekanan_kedalams', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('ppat_rekanan_id');
                $table->string('no_akta', 80);
                $table->date('tanggal_akta');
                $table->string('id_akta', 15)->nullable();
                $table->string('nama_akta_manual')->nullable();
                $table->text('pihak_mengalihkan')->nullable();
                $table->text('pihak_menerima')->nullable();
                $table->string('no_hak_milik')->nullable();
                $table->decimal('luas_tanah', 12, 2)->nullable();
                $table->decimal('luas_bangunan', 12, 2)->nullable();
                $table->text('harga_transaksi')->nullable();
                $table->string('nop')->nullable();
                $table->text('harga_njop')->nullable();
                $table->date('tgl_bphtb')->nullable();
                $table->text('harga_bphtb')->nullable();
                $table->date('tgl_pph')->nullable();
                $table->text('harga_pph')->nullable();
                $table->text('keterangan')->nullable();
                $table->string('created_by', 15)->nullable();
                $table->timestamps();

                $table->index(['tanggal_akta', 'no_akta']);
                $table->foreign('ppat_rekanan_id')->references('id')->on('ppat_rekanans')->onDelete('restrict');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('ppat_rekanan_kedalams');

        if (Schema::hasTable('buku_ppats')) {
            Schema::table('buku_ppats', function (Blueprint $table) {
                if (Schema::hasColumn('buku_ppats', 'rekanan_keluar_at')) {
                    $table->dropColumn('rekanan_keluar_at');
                }
                if (Schema::hasColumn('buku_ppats', 'rekanan_keluar_catatan')) {
                    $table->dropColumn('rekanan_keluar_catatan');
                }
                if (Schema::hasColumn('buku_ppats', 'ppat_rekanan_keluar_id')) {
                    $table->dropColumn('ppat_rekanan_keluar_id');
                }
            });
        }

        Schema::dropIfExists('ppat_rekanans');
    }
}

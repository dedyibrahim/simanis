<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('peminjaman_minutas', function (Blueprint $table) {
            if (!Schema::hasColumn('peminjaman_minutas', 'is_terlambat')) {
                $table->boolean('is_terlambat')->default(false)->after('status');
            }
            if (!Schema::hasColumn('peminjaman_minutas', 'jumlah_perpanjangan')) {
                $table->integer('jumlah_perpanjangan')->default(0)->after('tanggal_kembali');
            }
            if (!Schema::hasColumn('peminjaman_minutas', 'tanggal_perpanjangan_terakhir')) {
                $table->date('tanggal_perpanjangan_terakhir')->nullable()->after('jumlah_perpanjangan');
            }
            if (!Schema::hasColumn('peminjaman_minutas', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
        });
    }

    public function down()
    {
        Schema::table('peminjaman_minutas', function (Blueprint $table) {
            if (Schema::hasColumn('peminjaman_minutas', 'is_terlambat')) $table->dropColumn('is_terlambat');
            if (Schema::hasColumn('peminjaman_minutas', 'jumlah_perpanjangan')) $table->dropColumn('jumlah_perpanjangan');
            if (Schema::hasColumn('peminjaman_minutas', 'tanggal_perpanjangan_terakhir')) $table->dropColumn('tanggal_perpanjangan_terakhir');
            if (Schema::hasColumn('peminjaman_minutas', 'updated_by')) $table->dropColumn('updated_by');
        });
    }
};

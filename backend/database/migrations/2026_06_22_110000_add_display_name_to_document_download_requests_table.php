<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('document_download_requests', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('file_name');
        });
    }

    public function down()
    {
        Schema::table('document_download_requests', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};

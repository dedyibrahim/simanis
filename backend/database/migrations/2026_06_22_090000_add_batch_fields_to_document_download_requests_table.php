<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('document_download_requests', function (Blueprint $table) {
            $table->string('batch_id', 80)->nullable()->after('id');
            $table->string('batch_label')->nullable()->after('batch_id');
            $table->index(['batch_id', 'status']);
        });
    }

    public function down()
    {
        Schema::table('document_download_requests', function (Blueprint $table) {
            $table->dropIndex(['batch_id', 'status']);
            $table->dropColumn(['batch_id', 'batch_label']);
        });
    }
};

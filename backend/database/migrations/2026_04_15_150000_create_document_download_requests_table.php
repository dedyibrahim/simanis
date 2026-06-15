<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('document_download_requests', function (Blueprint $table) {
            $table->id();
            $table->string('module_path', 80);
            $table->string('row_id', 80);
            $table->string('file_name');
            $table->string('file_category', 50);
            $table->string('requested_by_id_user', 20);
            $table->string('requested_by_name')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('approved_by_id_user', 20)->nullable();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['module_path', 'row_id']);
            $table->index(['requested_by_id_user', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_download_requests');
    }
};

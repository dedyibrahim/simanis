<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scanned_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_session_id')->nullable()->constrained('scan_sessions')->nullOnDelete();
            $table->foreignId('assistant_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('original_name');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 120)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 30)->default('uploaded');
            $table->text('note')->nullable();
            $table->string('uploaded_by_agent')->nullable();
            $table->timestamps();

            $table->index(['assistant_user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scanned_documents');
    }
};

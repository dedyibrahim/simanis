<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('assistant_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('waiting');
            $table->string('output_type', 20)->default('pdf');
            $table->text('note')->nullable();
            $table->string('created_ip', 80)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['assistant_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_sessions');
    }
};

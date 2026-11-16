<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('login_otp_enabled')->default(false)->after('phone');
        });

        Schema::create('login_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('challenge_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'consumed_at', 'expires_at'], 'login_otp_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otp_challenges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('login_otp_enabled');
        });
    }
};

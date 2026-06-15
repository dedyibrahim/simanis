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
        Schema::create('waha_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('default')->unique();
            $table->string('base_url')->nullable();
            $table->string('api_key')->nullable();
            $table->string('send_message_endpoint')->default('/send-message');
            $table->string('check_number_endpoint')->default('/is-registered');
            $table->string('status_endpoint')->default('/status');
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
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
        Schema::dropIfExists('waha_configs');
    }
};


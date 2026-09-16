<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('whatsapp_broadcast_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');
            $table->unsignedBigInteger('sender_id');
            $table->unsignedBigInteger('recipient_id');
            $table->string('recipient_name');
            $table->string('phone', 20);
            $table->text('message');
            $table->string('status', 20)->default('processing');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['sender_id', 'batch_id', 'phone'], 'wa_broadcast_recipient_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('whatsapp_broadcast_deliveries');
    }
};

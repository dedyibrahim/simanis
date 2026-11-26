<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->string('id_order', 15);
            $table->foreign('id_order')->references('id_order')->on('orders');
            $table->string('type', 20);
            $table->string('title');
            $table->string('id_user', 15);
            $table->foreign('id_user')->references('id_user')->on('users');
            $table->string('created_by', 15);
            $table->foreign('created_by')->references('id_user')->on('users');
            $table->json('details')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->string('book_id', 15)->nullable();
            $table->string('book_number')->nullable();
            $table->timestamps();
            $table->index(['id_order', 'status']);
        });

        Schema::create('work_order_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->string('id_client', 15);
            $table->foreign('id_client')->references('id_client')->on('data_clients');
            $table->string('status_kedudukan', 100);
            $table->string('id_mewakili', 15)->nullable();
            $table->foreign('id_mewakili')->references('id_client')->on('data_clients');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_participants');
        Schema::dropIfExists('work_order_items');
    }
};

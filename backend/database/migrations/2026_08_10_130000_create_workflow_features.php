<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->string('work_number', 24)->unique();
            $table->string('title', 500);
            $table->string('category', 30)->default('custom');
            $table->string('client_id', 15)->nullable()->index();
            $table->string('service_id', 15)->nullable();
            $table->string('assignee_id', 15)->nullable()->index();
            $table->string('created_by', 15)->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->string('priority', 15)->default('normal');
            $table->string('billing_status', 30)->default('not_ready');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('legacy_order_id', 15)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('work_item_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('module_type', 40);
            $table->string('record_id', 40)->nullable();
            $table->string('relationship_type', 30)->default('output');
            $table->string('label', 500);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['work_item_id', 'module_type', 'record_id'], 'work_link_unique');
        });

        Schema::create('work_item_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('title', 500);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_completed')->default(false);
            $table->string('completed_by', 15)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('work_item_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->string('actor_id', 15)->nullable();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('work_item_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->boolean('taxable')->default(false);
            $table->timestamps();
        });

        Schema::create('work_item_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_type', 20)->default('non_tax');
            $table->string('status', 30)->default('draft');
            $table->string('invoice_number', 80)->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('grand_total')->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_invoices');
        Schema::dropIfExists('work_item_costs');
        Schema::dropIfExists('work_item_activities');
        Schema::dropIfExists('work_item_checklists');
        Schema::dropIfExists('work_item_links');
        Schema::dropIfExists('work_items');
    }
};

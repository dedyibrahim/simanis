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
        Schema::create('report_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('default')->unique();
            $table->string('header_label')->default('KANTOR NOTARIS / PPAT');
            $table->string('office_name');
            $table->text('office_address');
            $table->string('office_email')->nullable();
            $table->string('office_phone')->nullable();
            $table->string('office_city')->default('Jakarta');
            $table->string('signatory_title')->default('Notaris / PPAT DKI Jakarta');
            $table->string('signatory_name');
            $table->text('invoice_bank_account_1')->nullable();
            $table->text('invoice_bank_account_2')->nullable();
            $table->text('invoice_bank_account_3')->nullable();
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
        Schema::dropIfExists('report_settings');
    }
};

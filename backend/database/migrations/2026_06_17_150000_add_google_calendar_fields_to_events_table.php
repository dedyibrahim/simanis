<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('google_event_id')->nullable()->unique()->after('creator_id');
            $table->timestamp('google_synced_at')->nullable()->after('google_event_id');
            $table->text('google_sync_error')->nullable()->after('google_synced_at');
        });
    }

    public function down()
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['google_event_id']);
            $table->dropColumn([
                'google_event_id',
                'google_synced_at',
                'google_sync_error',
            ]);
        });
    }
};

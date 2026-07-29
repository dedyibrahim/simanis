<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scanned_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('scanned_documents', 'posted_module')) {
                $table->string('posted_module', 80)->nullable()->after('status');
            }
            if (!Schema::hasColumn('scanned_documents', 'posted_record_id')) {
                $table->string('posted_record_id', 80)->nullable()->after('posted_module');
            }
            if (!Schema::hasColumn('scanned_documents', 'posted_document_id')) {
                $table->string('posted_document_id', 80)->nullable()->after('posted_record_id');
            }
            if (!Schema::hasColumn('scanned_documents', 'posted_file_path')) {
                $table->string('posted_file_path')->nullable()->after('posted_document_id');
            }
            if (!Schema::hasColumn('scanned_documents', 'posted_by')) {
                $table->foreignId('posted_by')->nullable()->after('posted_file_path')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('scanned_documents', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('posted_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scanned_documents', function (Blueprint $table) {
            foreach (['posted_at', 'posted_by', 'posted_file_path', 'posted_document_id', 'posted_record_id', 'posted_module'] as $column) {
                if (Schema::hasColumn('scanned_documents', $column)) {
                    if ($column === 'posted_by') {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};

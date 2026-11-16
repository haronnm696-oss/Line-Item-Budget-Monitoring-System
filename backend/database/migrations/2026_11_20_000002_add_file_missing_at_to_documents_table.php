<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a document whose file could not be found (for example an old upload
 * on a disk that was wiped). The row is kept; the UI shows "File not
 * available". Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('file_missing_at')->nullable()->after('uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('file_missing_at');
        });
    }
};

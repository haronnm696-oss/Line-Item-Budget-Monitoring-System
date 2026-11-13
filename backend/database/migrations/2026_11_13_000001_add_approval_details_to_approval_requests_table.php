<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Capture why an approval was requested and who reviewed it. Nullable so
     * existing approval rows remain valid without a backfill.
     */
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->text('justification')->nullable()->after('description');
            $table->string('reviewed_by_name')->nullable()->after('reviewed_by');
            $table->string('reviewed_by_role')->nullable()->after('reviewed_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn(['justification', 'reviewed_by_name', 'reviewed_by_role']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Return / resubmit / withdraw for budget requests. A Program Leader's
 * "Return" keeps the stored status `rejected` (the existing enum value, so
 * existing rows and the enum are untouched); the API shows it as
 * "returned". These nullable columns record a withdrawal and the last
 * resubmission. Additive only: existing rows keep their data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('reviewed_at');
            $table->foreignId('withdrawn_by')->nullable()->after('withdrawn_at')->constrained('users')->nullOnDelete();
            $table->timestamp('resubmitted_at')->nullable()->after('withdrawn_by');
            $table->text('last_return_reason')->nullable()->after('resubmitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn(['withdrawn_at', 'resubmitted_at', 'last_return_reason']);
        });
    }
};

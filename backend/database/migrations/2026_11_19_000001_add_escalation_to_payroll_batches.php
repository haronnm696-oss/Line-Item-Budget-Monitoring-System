<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-pay escalation: when a payroll batch includes pay for the Project
 * Leader who would approve it, or no Finance/Admin without pay in it can
 * verify it, that step goes to the Program Leader as a pending "payroll"
 * approval request. The batch keeps a link to its latest such request.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_batches', function (Blueprint $table) {
            $table->foreignId('approval_request_id')->nullable()->after('return_reason')->constrained('approval_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_request_id');
        });
    }
};

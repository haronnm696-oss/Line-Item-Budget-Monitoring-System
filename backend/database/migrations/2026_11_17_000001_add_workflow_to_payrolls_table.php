<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll gets the same workflow as expenses (draft -> submitted -> endorsed
 * -> verified, or returned) plus an allowance column. Additive only: the old
 * `status` enum (draft / approved / released) is kept and synced
 * (Payroll::STATUS_FOR_WORKFLOW). Existing rows are backfilled:
 * draft -> draft, approved / released -> verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('allowance', 15, 2)->default(0)->after('basic_rate');
            $table->string('workflow_status', 20)->nullable()->after('status');
            $table->timestamp('submitted_at')->nullable()->after('workflow_status');
            $table->foreignId('pl_reviewed_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('pl_reviewed_at')->nullable()->after('pl_reviewed_by');
            $table->foreignId('verified_by')->nullable()->after('pl_reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('return_reason')->nullable()->after('verified_at');

            $table->index(['project_id', 'pay_year', 'pay_month', 'workflow_status'], 'payrolls_period_workflow_index');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropIndex('payrolls_period_workflow_index');
            $table->dropConstrainedForeignId('pl_reviewed_by');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['allowance', 'workflow_status', 'submitted_at', 'pl_reviewed_at', 'verified_at', 'return_reason']);
        });
    }

    /** Only rows without a workflow status (safe to rerun). */
    public function backfill(): void
    {
        DB::table('payrolls')->whereNull('workflow_status')->where('status', 'draft')->update([
            'workflow_status' => 'draft',
        ]);

        DB::table('payrolls')->whereNull('workflow_status')->whereIn('status', ['approved', 'released'])->update([
            'workflow_status' => 'verified',
            'submitted_at' => DB::raw('created_at'),
            'verified_at' => DB::raw('updated_at'),
        ]);
    }
};

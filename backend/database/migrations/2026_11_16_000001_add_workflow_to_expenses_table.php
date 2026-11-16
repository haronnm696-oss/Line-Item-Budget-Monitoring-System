<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: the expense workflow (draft -> submitted -> endorsed -> verified,
 * or returned) next to the existing `status` enum, which stays the only thing
 * that decides "spent" (status = verified). Soft deletes for drafts.
 * Nothing is dropped, renamed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('workflow_status', 20)->nullable()->after('status');
            $table->timestamp('submitted_at')->nullable()->after('workflow_status');
            $table->foreignId('pl_reviewed_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('pl_reviewed_at')->nullable()->after('pl_reviewed_by');
            $table->text('return_reason')->nullable()->after('pl_reviewed_at');
            $table->softDeletes();
            $table->index(['project_id', 'workflow_status']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'workflow_status']);
            $table->dropConstrainedForeignId('pl_reviewed_by');
            $table->dropColumn(['workflow_status', 'submitted_at', 'pl_reviewed_at', 'return_reason', 'deleted_at']);
        });
    }

    /**
     * Existing rows: pending -> submitted, verified -> verified,
     * rejected -> returned (remarks copied into return_reason).
     * Only rows without a workflow status are touched, so it is safe to rerun.
     */
    public function backfill(): void
    {
        DB::table('expenses')->whereNull('workflow_status')->where('status', 'pending')->update([
            'workflow_status' => 'submitted',
            'submitted_at' => DB::raw('created_at'),
        ]);

        DB::table('expenses')->whereNull('workflow_status')->where('status', 'verified')->update([
            'workflow_status' => 'verified',
            'submitted_at' => DB::raw('created_at'),
        ]);

        DB::table('expenses')->whereNull('workflow_status')->where('status', 'rejected')->update([
            'workflow_status' => 'returned',
            'return_reason' => DB::raw('remarks'),
            'submitted_at' => DB::raw('created_at'),
        ]);
    }
};

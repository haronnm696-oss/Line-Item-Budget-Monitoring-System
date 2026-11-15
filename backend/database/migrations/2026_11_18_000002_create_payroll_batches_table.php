<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll is approved per month: one batch per project and month groups the
 * payroll rows, and submit / approve / return / verify / reject act on the
 * batch. Additive only: payrolls gets a nullable payroll_batch_id and keeps
 * its per-row workflow columns (kept in step with the batch).
 *
 * Existing rows are grouped into batches (see backfill()).
 */
return new class extends Migration
{
    /** Least advanced first: a batch takes the earliest stage among its rows. */
    public const STAGE_ORDER = ['draft', 'returned', 'submitted', 'endorsed', 'verified'];

    public function up(): void
    {
        Schema::create('payroll_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('pay_year');
            $table->unsignedTinyInteger('pay_month');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('workflow_status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pl_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pl_reviewed_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'pay_year', 'pay_month'], 'payroll_batches_period_unique');
            $table->index(['project_id', 'workflow_status']);
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreignId('payroll_batch_id')->nullable()->after('project_id')->constrained('payroll_batches')->nullOnDelete();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_batch_id');
        });
        Schema::dropIfExists('payroll_batches');
    }

    /**
     * One batch per project + month for rows without a batch (safe to
     * rerun). The batch takes the least advanced stage of its rows, so
     * nothing counts as approved unless every row was; rows themselves are
     * left unchanged. The earliest preparer becomes the batch preparer.
     */
    public function backfill(): void
    {
        $periods = DB::table('payrolls')
            ->whereNull('payroll_batch_id')
            ->select('project_id', 'pay_year', 'pay_month')
            ->distinct()
            ->get();

        foreach ($periods as $period) {
            $rows = DB::table('payrolls')
                ->where('project_id', $period->project_id)
                ->where('pay_year', $period->pay_year)
                ->where('pay_month', $period->pay_month)
                ->orderBy('id')
                ->get();

            $stages = $rows->map(fn ($row) => $row->workflow_status ?: ($row->status === 'draft' ? 'draft' : 'verified'));
            $stage = collect(self::STAGE_ORDER)->first(fn ($s) => $stages->contains($s)) ?? 'draft';
            $first = $rows->first();

            $batchId = DB::table('payroll_batches')->where([
                'project_id' => $period->project_id,
                'pay_year' => $period->pay_year,
                'pay_month' => $period->pay_month,
            ])->value('id');

            if (! $batchId) {
                $batchId = DB::table('payroll_batches')->insertGetId([
                    'project_id' => $period->project_id,
                    'pay_year' => $period->pay_year,
                    'pay_month' => $period->pay_month,
                    'prepared_by' => $first->computed_by,
                    'workflow_status' => $stage,
                    'submitted_at' => in_array($stage, ['submitted', 'endorsed', 'verified'], true) ? $rows->min('submitted_at') ?? $first->created_at : null,
                    'verified_at' => $stage === 'verified' ? $rows->max('verified_at') ?? $first->updated_at : null,
                    'created_at' => $first->created_at,
                    'updated_at' => now(),
                ]);
            }

            DB::table('payrolls')->whereIn('id', $rows->pluck('id'))->update(['payroll_batch_id' => $batchId]);
        }
    }
};

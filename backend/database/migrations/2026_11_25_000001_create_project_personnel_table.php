<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Project personnel: the project team appointed by Special Order, paid PS
 * honoraria through payroll (Job Order and professional-services staff are
 * paid through normal expenses). This is now the ONLY source of pay for
 * payroll; project_user.monthly_rate / allowance are legacy and no longer
 * read or written (kept, not dropped: migrations are additive).
 *
 * Existing pay rates (project_user.monthly_rate not null) become personnel
 * records: position = the person's account role, line item = the project's
 * first PS line item (if any), effective = the project period, monthly
 * honorarium = the monthly rate. Allowances are not carried over (payroll
 * covers honoraria only); they stay in project_user.allowance untouched.
 * Safe to rerun: a person who already has a record on the project is skipped.
 */
class CreateProjectPersonnelTable extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_personnel')) {
            Schema::create('project_personnel', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('position', 255);
                $table->foreignId('lib_category_id')->nullable()->constrained('lib_categories')->nullOnDelete();
                $table->string('special_order_no', 100)->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->decimal('monthly_honorarium', 15, 2)->default(0);
                // Encrypted with the app key (Laravel "encrypted" cast); never in normal API responses.
                $table->text('bank_account')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['project_id', 'user_id']);
            });
        }

        $report = $this->migratePayRates();
        Log::info('project_personnel: migrated pay rates', $report);
    }

    public function down(): void
    {
        // Additive migration: the table is kept (production data).
    }

    /**
     * project_user rows with a monthly rate -> personnel records.
     *
     * @return array{migrated: int, skipped_existing: int, without_ps_line_item: int, allowances_not_carried: int}
     */
    public function migratePayRates(): array
    {
        $report = ['migrated' => 0, 'skipped_existing' => 0, 'without_ps_line_item' => 0, 'allowances_not_carried' => 0];
        $rows = DB::table('project_user')
            ->whereNotNull('monthly_rate')
            ->orderBy('id')
            ->get(['id', 'project_id', 'user_id', 'role_id', 'monthly_rate', 'allowance']);

        $seen = [];
        foreach ($rows as $row) {
            $key = $row->project_id . ':' . $row->user_id;
            if (isset($seen[$key])) {
                continue; // one record per person per project (their first rated assignment)
            }
            $seen[$key] = true;

            if (DB::table('project_personnel')->where('project_id', $row->project_id)->where('user_id', $row->user_id)->exists()) {
                $report['skipped_existing']++;
                continue;
            }
            $project = DB::table('projects')->where('id', $row->project_id)->first(['start_date', 'end_date']);
            $user = DB::table('users')->where('id', $row->user_id)->first(['role_id']);
            if (! $project || ! $user) {
                continue;
            }
            $position = DB::table('roles')->where('id', $user->role_id)->value('name')
                ?? DB::table('roles')->where('id', $row->role_id)->value('name')
                ?? 'Project staff';
            $lineItem = DB::table('lib_categories')
                ->where('project_id', $row->project_id)->where('category_type', 'PS')
                ->orderBy('id')->value('id');

            DB::table('project_personnel')->insert([
                'project_id' => $row->project_id,
                'user_id' => $row->user_id,
                'position' => $position,
                'lib_category_id' => $lineItem,
                'special_order_no' => null,
                'effective_from' => $project->start_date,
                'effective_to' => $project->end_date,
                'monthly_honorarium' => $row->monthly_rate,
                'bank_account' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $report['migrated']++;
            if ($lineItem === null) {
                $report['without_ps_line_item']++;
            }
            if ((float) $row->allowance > 0) {
                $report['allowances_not_carried']++;
            }
        }

        return $report;
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give money columns that code may leave unset a DEFAULT 0 again (still
 * NOT NULL). 2026_11_03_000001 widened money columns with
 * "ALTER TABLE ... MODIFY col DECIMAL(15, 2) NOT NULL"; a MySQL MODIFY
 * replaces the whole column definition, so it silently dropped the
 * DEFAULT 0 these columns had. In strict mode (production, Aiven) an
 * insert that leaves one of them out then fails with
 * "1364 Field ... doesn't have a default value", which is what broke
 * New project once the counterpart moved to the line items.
 *
 * Checked on a fresh MySQL schema: every NOT NULL column without a default
 * on projects, project_tranches, lib_categories, project_personnel,
 * expenses, purchase_requests, payrolls, payroll_batches and
 * payroll_deductions. Only the columns below can be left unset by the
 * code (or had a default before). The rest (keys, codes, titles, dates,
 * amounts the user enters: expenses.amount, project_tranches.amount,
 * purchase_requests.estimated_amount, payrolls.basic_rate / gross_pay /
 * net_pay, payroll_deductions.amount) are required by validation and set
 * on every insert, so they stay without a default: a missing value there
 * is a bug that should fail, not a silent 0.
 *
 * Additive only: no data changes, and nothing is narrowed. SQLite (tests)
 * keeps the defaults from the original migrations and cannot MODIFY, so
 * it is skipped there.
 */
return new class extends Migration
{
    private const COLUMNS = [
        // Legacy typed figures; amounts now live on the line items (never set by New project).
        'projects' => ['implementing_agency_counterpart', 'funding_agency_amount', 'total_approved_budget'],
        // Kept in sync by LibCategory::saving; a default for writers that bypass it.
        'lib_categories' => ['allocated_amount'],
        // Had DEFAULT 0 before 2026_11_03_000001.
        'payrolls' => ['total_deductions'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` DECIMAL(15, 2) NOT NULL DEFAULT 0.00");
            }
        }
    }

    public function down(): void
    {
        // A default is harmless to keep; removing it would bring the strict-mode failure back.
    }
};

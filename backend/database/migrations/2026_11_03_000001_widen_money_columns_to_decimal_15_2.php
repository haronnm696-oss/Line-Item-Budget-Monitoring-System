<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen existing money columns from DECIMAL(12/14, 2) to DECIMAL(15, 2).
     * This only increases precision; no values are dropped or rounded.
     * SQLite (used by the test suite) has no fixed decimal precision, so the
     * ALTER is skipped there.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $columns = [
            'projects' => [
                'total_approved_budget',
                'implementing_agency_counterpart',
                'funding_agency_amount',
            ],
            'lib_categories' => ['allocated_amount'],
            'expenses' => ['amount'],
            'payrolls' => ['basic_rate', 'gross_pay', 'total_deductions', 'net_pay'],
            'payroll_deductions' => ['amount'],
            'project_tranches' => ['amount'],
            'approval_requests' => ['amount'],
        ];

        // approval_requests.amount is the only nullable money column in the
        // existing schema; all others are NOT NULL. Preserve both.
        $nullable = ['approval_requests.amount'];

        foreach ($columns as $table => $names) {
            foreach ($names as $column) {
                $nullability = in_array("{$table}.{$column}", $nullable, true) ? 'NULL' : 'NOT NULL';

                DB::statement(
                    "ALTER TABLE `{$table}` MODIFY `{$column}` DECIMAL(15, 2) {$nullability}"
                );
            }
        }
    }

    public function down(): void
    {
        // Precision is not narrowed on rollback: doing so could silently
        // round real financial data.
    }
};

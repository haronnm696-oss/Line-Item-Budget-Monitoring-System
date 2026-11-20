<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll periods: a batch covers one or more consecutive months (e.g. a
 * quarter). pay_year / pay_month stay the FIRST month; the new columns hold
 * the last month (null = a one-month batch, every batch made before this).
 *
 * Each row records how many months of the period the person's appointment
 * was effective and how the amount was made, e.g. one segment
 * {"honorarium": 8800, "months": 3}. Null on older rows (one month).
 *
 * Project honoraria have no deductions and no allowances. Additive only:
 * payrolls.allowance, payrolls.total_deductions and payroll_deductions stay
 * so verified batches keep exactly what was recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_batches', function (Blueprint $table) {
            $table->unsignedSmallInteger('period_end_year')->nullable()->after('pay_month');
            $table->unsignedTinyInteger('period_end_month')->nullable()->after('period_end_year');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->unsignedTinyInteger('months')->nullable()->after('basic_rate');
            $table->json('computation')->nullable()->after('months');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['months', 'computation']);
        });
        Schema::table('payroll_batches', function (Blueprint $table) {
            $table->dropColumn(['period_end_year', 'period_end_month']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R&D Form B (Project Line-Item Budget) structure. Additive only.
 *
 * lib_categories
 *   cost_type                   Direct | Indirect (default Direct)
 *   expense_group               e.g. "Honoraria", "Supplies and Materials Expenses"
 *   expense_subgroup            optional one level below the group
 *   quantity, unit_description, rate, rate_period (month|quarter), periods
 *                               optional computation ("One (1) Project Leader
 *                               @ ₱8,800.00/mo. x 12 mos.")
 *   implementing_agency_amount  counterpart share
 *   funder_amount               the project's funder share
 *   allocated_amount (existing) stays = implementing_agency_amount + funder_amount
 *
 * expenses, purchase_requests
 *   funding_source              funder | implementing_agency (default funder)
 *
 * Existing rows: cost_type Direct, expense_group = line item name,
 * funder_amount = allocated_amount, implementing_agency_amount = 0.
 * Only rows not yet backfilled are touched, so it is safe to rerun.
 * projects.implementing_agency_counterpart / funding_agency_amount are left
 * as they are (no data is overwritten); screens now read the line-item sums.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lib_categories', 'funder_amount')) {
            Schema::table('lib_categories', function (Blueprint $table) {
                $table->string('cost_type', 10)->default('Direct')->after('category_type');
                $table->string('expense_group')->nullable()->after('cost_type');
                $table->string('expense_subgroup')->nullable()->after('expense_group');
                $table->unsignedInteger('quantity')->nullable()->after('line_item_code');
                $table->string('unit_description')->nullable()->after('quantity');
                $table->decimal('rate', 15, 2)->nullable()->after('unit_description');
                $table->string('rate_period', 10)->nullable()->after('rate');
                $table->unsignedInteger('periods')->nullable()->after('rate_period');
                $table->decimal('implementing_agency_amount', 15, 2)->default(0)->after('periods');
                $table->decimal('funder_amount', 15, 2)->default(0)->after('implementing_agency_amount');
            });
        }

        if (! Schema::hasColumn('expenses', 'funding_source')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->string('funding_source', 20)->default('funder')->after('lib_category_id');
            });
        }

        if (! Schema::hasColumn('purchase_requests', 'funding_source')) {
            Schema::table('purchase_requests', function (Blueprint $table) {
                $table->string('funding_source', 20)->default('funder')->after('lib_category_id');
            });
        }

        $this->backfill();
    }

    /** Rows without a group are the ones written before this migration. */
    public function backfill(): void
    {
        DB::table('lib_categories')->whereNull('expense_group')->update([
            'cost_type' => 'Direct',
            'expense_group' => DB::raw('line_item_name'),
            'funder_amount' => DB::raw('allocated_amount'),
            'implementing_agency_amount' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_requests', fn (Blueprint $table) => $table->dropColumn('funding_source'));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('funding_source'));
        Schema::table('lib_categories', fn (Blueprint $table) => $table->dropColumn([
            'cost_type', 'expense_group', 'expense_subgroup', 'quantity', 'unit_description',
            'rate', 'rate_period', 'periods', 'implementing_agency_amount', 'funder_amount',
        ]));
    }
};

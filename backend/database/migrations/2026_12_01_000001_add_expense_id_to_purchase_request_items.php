<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses link to purchase request items, not whole line items: each item
 * records the expense that pays it (nullable: an open item has none).
 * Additive only.
 *
 * Backfill: until now an expense linked to a request took every item of the
 * request on the expense's line item, so those items get that expense
 * (deleted expenses are skipped; their items stay open).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_request_items', 'expense_id')) {
            Schema::table('purchase_request_items', function (Blueprint $table) {
                $table->foreignId('expense_id')->nullable()->after('lib_category_id')->constrained('expenses')->nullOnDelete();
            });
        }

        $this->backfill();
    }

    /** Safe to run again: only items without an expense are filled. */
    public function backfill(): void
    {
        DB::table('expenses')
            ->whereNotNull('purchase_request_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select(['id', 'purchase_request_id', 'lib_category_id'])
            ->chunkById(200, function ($expenses) {
                foreach ($expenses as $expense) {
                    DB::table('purchase_request_items')
                        ->where('purchase_request_id', $expense->purchase_request_id)
                        ->where('lib_category_id', $expense->lib_category_id)
                        ->whereNull('expense_id')
                        ->update(['expense_id' => $expense->id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
        });
    }
};

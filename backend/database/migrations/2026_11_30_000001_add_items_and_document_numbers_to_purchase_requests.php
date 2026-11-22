<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MSU's Purchase Request form: one request lists several items, each charged
 * to its own line item. Additive only.
 *
 * - purchase_request_items: quantity, unit, description, estimated unit cost,
 *   estimated cost (quantity x unit cost) and the line item (plus the funding
 *   source resolved for that line item).
 * - purchase_requests: PR No., SAI No. and ALOBS No. with their dates, which
 *   Finance/Admin fills in after approval. The old single-item columns stay
 *   and now hold the request summary (description = purpose, quantity = total
 *   quantity, estimated_amount = total, lib_category_id = first item's line).
 *
 * Every existing request becomes one item: quantity = its quantity, unit
 * "lot", unit cost = amount / quantity, estimated cost = its amount exactly
 * (so totals never change, even when the division is not exact).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_request_items')) {
            Schema::create('purchase_request_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
                $table->foreignId('lib_category_id')->constrained()->cascadeOnDelete();
                $table->string('funding_source', 20)->default('funder');
                $table->unsignedInteger('quantity')->default(1);
                $table->string('unit', 10)->default('lot');
                $table->string('description', 500)->default('');
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('estimated_cost', 15, 2)->default(0);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['lib_category_id', 'purchase_request_id']);
            });
        }

        Schema::table('purchase_requests', function (Blueprint $table) {
            foreach (['pr', 'sai', 'alobs'] as $doc) {
                if (! Schema::hasColumn('purchase_requests', "{$doc}_no")) {
                    $table->string("{$doc}_no", 100)->nullable();
                    $table->date("{$doc}_date")->nullable();
                }
            }
        });

        $this->backfill();
    }

    /** One item per existing request without items (soft-deleted ones too); safe to run again. */
    public function backfill(): void
    {
        $now = now();
        DB::table('purchase_requests')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('purchase_request_items')
                ->whereColumn('purchase_request_items.purchase_request_id', 'purchase_requests.id'))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($now) {
                DB::table('purchase_request_items')->insert($rows->map(function ($row) use ($now) {
                    $quantity = max(1, (int) $row->quantity);
                    $amount = round((float) $row->estimated_amount, 2);

                    return [
                        'purchase_request_id' => $row->id,
                        'lib_category_id' => $row->lib_category_id,
                        'funding_source' => $row->funding_source ?: 'funder',
                        'quantity' => $quantity,
                        'unit' => 'lot',
                        'description' => (string) $row->description,
                        'unit_cost' => round($amount / $quantity, 2),
                        'estimated_cost' => $amount,
                        'sort_order' => 0,
                        'created_at' => $row->created_at ?? $now,
                        'updated_at' => $now,
                    ];
                })->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['pr_no', 'pr_date', 'sai_no', 'sai_date', 'alobs_no', 'alobs_date']);
        });
    }
};

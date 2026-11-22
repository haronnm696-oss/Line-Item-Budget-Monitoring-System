<?php

namespace App\Support;

use App\Models\LibCategory;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;

/**
 * The item table of a purchase request as every screen shows it (the
 * Research Assistant's pages, the Project Leader's review panel, the Program
 * Leader's project page, Finance/Admin), so all of them read the same rows.
 */
final class PurchaseRequestItems
{
    /**
     * Each item with its line item, and whether an expense is already linked
     * for that line item.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(PurchaseRequest $request): array
    {
        $linked = ($request->relationLoaded('expenses') ? $request->expenses : $request->expenses()->get())
            ->pluck('lib_category_id')->map(fn ($id) => (int) $id)->all();

        return $request->lineItems()->map(function (PurchaseRequestItem $item) use ($linked) {
            $item->loadMissing('libCategory');

            return [
                'id' => $item->id,
                'lib_category_id' => (int) $item->lib_category_id,
                'line_item' => $item->libCategory?->line_item_name,
                'line_item_type' => $item->libCategory?->category_type,
                'funding_source' => $item->funding_source ?: LibCategory::SOURCE_FUNDER,
                'quantity' => (int) $item->quantity,
                'unit' => $item->unit,
                'description' => $item->description,
                'unit_cost' => round((float) $item->unit_cost, 2),
                'estimated_cost' => round((float) $item->estimated_cost, 2),
                'expense_linked' => in_array((int) $item->lib_category_id, $linked, true),
            ];
        })->values()->all();
    }

    /** "Supplies" or "3 line items", for list rows. */
    public static function lineSummary(PurchaseRequest $request): ?string
    {
        $lines = collect(self::rows($request))->pluck('line_item')->filter()->unique()->values();

        return $lines->count() > 1 ? $lines->count() . ' line items' : ($lines->first() ?? $request->libCategory?->line_item_name);
    }

    /** PR / SAI / ALOBS numbers and dates as label/value fields (empty ones left out). */
    public static function numberFields(PurchaseRequest $request): array
    {
        $fields = [];
        foreach (['pr' => 'PR No.', 'sai' => 'SAI No.', 'alobs' => 'ALOBS No.'] as $key => $label) {
            $no = $request->{"{$key}_no"};
            $date = $request->{"{$key}_date"};
            if ($no || $date) {
                $fields[] = ['label' => $label, 'value' => trim(($no ?: '—') . ($date ? ' · ' . $date->format('M j, Y') : ''))];
            }
        }

        return $fields;
    }
}

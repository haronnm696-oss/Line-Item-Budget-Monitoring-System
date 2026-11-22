<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a purchase request (MSU Purchase Request form): quantity, unit,
 * description, estimated unit cost and estimated cost (quantity x unit cost,
 * computed by PurchaseRequestService), charged to its own line item.
 *
 * expense_id: the expense that pays the item. Status:
 *   open    no expense yet (an approved request holds its cost as pending)
 *   linked  the expense is not verified yet ("Linked (pending)")
 *   paid    the expense is verified
 */
class PurchaseRequestItem extends Model
{
    public const UNITS = ['pc', 'set', 'box', 'lot', 'ream', 'unit'];

    public const STATUS_LABELS = ['open' => 'Open', 'linked' => 'Linked (pending)', 'paid' => 'Paid'];

    protected $attributes = ['funding_source' => 'funder', 'unit' => 'lot', 'sort_order' => 0];

    protected $fillable = [
        'purchase_request_id', 'lib_category_id', 'expense_id', 'funding_source', 'quantity', 'unit', 'description',
        'unit_cost', 'estimated_cost', 'sort_order',
    ];

    protected $casts = [
        'expense_id' => 'integer',
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class)->withTrashed();
    }

    public function libCategory(): BelongsTo
    {
        return $this->belongsTo(LibCategory::class);
    }

    /** The expense that pays this item (a deleted expense frees its items, see PurchaseRequestService::release). */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** open | linked | paid */
    public function status(): string
    {
        if (! $this->expense_id) {
            return 'open';
        }
        $expense = $this->expense;

        return $expense && $expense->workflow_status === 'verified' ? 'paid' : 'linked';
    }
}

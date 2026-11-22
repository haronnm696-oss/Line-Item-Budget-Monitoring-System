<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a purchase request (MSU Purchase Request form): quantity, unit,
 * description, estimated unit cost and estimated cost (quantity x unit cost,
 * computed by PurchaseRequestService), charged to its own line item.
 */
class PurchaseRequestItem extends Model
{
    public const UNITS = ['pc', 'set', 'box', 'lot', 'ream', 'unit'];

    protected $attributes = ['funding_source' => 'funder', 'unit' => 'lot', 'sort_order' => 0];

    protected $fillable = [
        'purchase_request_id', 'lib_category_id', 'funding_source', 'quantity', 'unit', 'description',
        'unit_cost', 'estimated_cost', 'sort_order',
    ];

    protected $casts = [
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
}

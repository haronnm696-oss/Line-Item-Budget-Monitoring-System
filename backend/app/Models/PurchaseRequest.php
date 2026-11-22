<?php

namespace App\Models;

use App\Models\Contracts\HasDocuments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A purchase request (MSU Purchase Request form): purpose (stored in
 * `description`), supplier, funding source, optional PR / SAI / ALOBS numbers
 * and dates (Finance/Admin, after approval), and one or more items, each
 * charged to its own line item (PurchaseRequestItem). The old single-item
 * columns hold the summary: quantity = total quantity, estimated_amount =
 * total of the items, lib_category_id = the first item's line item.
 * Workflow: draft -> submitted
 * -> endorsed ("Approved by Project Leader") or returned. No Finance step.
 * An approved request then ends as "fulfilled" (an expense is linked to it)
 * or "cancelled" (with a reason).
 */
class PurchaseRequest extends Model implements HasDocuments
{
    use SoftDeletes;

    /** Document types that count as the quotation / invoice a submit needs. */
    public const SUPPORTING_TYPES = ['quotation', 'invoice'];

    /** Approved and still open: the only stage that counts as pending money. */
    public const OPEN_APPROVED = 'endorsed';

    /** Funder by default; implementing_agency draws on the counterpart share. */
    protected $attributes = ['funding_source' => 'funder'];

    protected $fillable = [
        'project_id', 'lib_category_id', 'funding_source', 'requested_by', 'description', 'quantity', 'estimated_amount', 'supplier',
        'workflow_status', 'submitted_at', 'pl_reviewed_by', 'pl_reviewed_at', 'return_reason',
        'fulfilled_at', 'fulfilled_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
        'pr_no', 'pr_date', 'sai_no', 'sai_date', 'alobs_no', 'alobs_date',
    ];

    /** Document numbers Finance/Admin fills in on an approved request. */
    public const NUMBER_FIELDS = ['pr_no', 'pr_date', 'sai_no', 'sai_date', 'alobs_no', 'alobs_date'];

    protected $casts = [
        'quantity' => 'integer',
        'estimated_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'pl_reviewed_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'pr_date' => 'date:Y-m-d',
        'sai_date' => 'date:Y-m-d',
        'alobs_date' => 'date:Y-m-d',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function libCategory(): BelongsTo
    {
        return $this->belongsTo(LibCategory::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function plReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pl_reviewed_by');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The (first) expense linked to this request. */
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class)->oldestOfMany();
    }

    /** Every expense linked to this request (one per line item of its items). */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The items; a request stored without items (made before items existed
     * and not migrated, or created directly) reads as one "lot" item built
     * from its own columns, so it still counts and displays.
     *
     * @return Collection<int, PurchaseRequestItem>
     */
    public function lineItems(): Collection
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        if ($items->isNotEmpty()) {
            return $items;
        }
        $quantity = max(1, (int) $this->quantity);
        $amount = round((float) $this->estimated_amount, 2);

        return collect([new PurchaseRequestItem([
            'purchase_request_id' => $this->id,
            'lib_category_id' => $this->lib_category_id,
            'funding_source' => $this->funding_source ?: LibCategory::SOURCE_FUNDER,
            'quantity' => $quantity,
            'unit' => 'lot',
            'description' => (string) $this->description,
            'unit_cost' => round($amount / $quantity, 2),
            'estimated_cost' => $amount,
        ])]);
    }

    /**
     * Item totals per line item and source: "lineId:source" =>
     * [lib_category_id, funding_source, amount].
     *
     * @return array<string, array{lib_category_id: int, funding_source: string, amount: float}>
     */
    public function amountsByLine(): array
    {
        $out = [];
        foreach ($this->lineItems() as $item) {
            $source = in_array($item->funding_source, LibCategory::SOURCES, true) ? $item->funding_source : LibCategory::SOURCE_FUNDER;
            $key = $item->lib_category_id . ':' . $source;
            $out[$key] ??= ['lib_category_id' => (int) $item->lib_category_id, 'funding_source' => $source, 'amount' => 0.0];
            $out[$key]['amount'] = round($out[$key]['amount'] + (float) $item->estimated_cost, 2);
        }

        return $out;
    }

    /** @return array<int, int> distinct line item ids of the items */
    public function lineItemIds(): array
    {
        return $this->lineItems()->pluck('lib_category_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * The stored items, creating the single "lot" item first for a request
     * stored without items, so an expense can be linked to a real row.
     *
     * @return Collection<int, PurchaseRequestItem>
     */
    public function storedItems(): Collection
    {
        if (! $this->items()->exists()) {
            foreach ($this->lineItems() as $item) {
                $item->save();
            }
        }
        $this->unsetRelation('items');

        return $this->items()->get();
    }

    /**
     * How far the items are paid for:
     *   full     every item is paid (its expense is verified)
     *   partial  some items have an expense (linked or paid), not all paid
     *   none     no item has an expense
     */
    public function fulfilment(): string
    {
        $items = $this->lineItems();
        if ($items instanceof \Illuminate\Database\Eloquent\Collection) {
            $items->loadMissing('expense');
        }
        $statuses = $items->map(fn (PurchaseRequestItem $item) => $item->status());
        if ($statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'paid')) {
            return 'full';
        }

        return $statuses->contains(fn ($status) => $status !== 'open') ? 'partial' : 'none';
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function supportingDocuments(): MorphMany
    {
        return $this->documents()->whereIn('document_type', self::SUPPORTING_TYPES);
    }

    public function documentRules(): array
    {
        return [
            'folder' => 'purchase-requests',
            'types' => ['quotation', 'invoice', 'other'],
            'uploader_roles' => ['research-assistant', 'finance-admin'],
            'uploader_message' => 'Only a Research Assistant or Finance/Admin staff of this project can attach documents.',
            'open' => in_array($this->workflow_status, ['draft', 'returned'], true),
            'closed_message' => 'Documents can be attached only while the purchase request is a draft or returned.',
            'locked' => in_array($this->workflow_status, ['endorsed', 'fulfilled', 'cancelled'], true),
            'locked_message' => 'Documents of an approved, fulfilled or cancelled purchase request cannot be deleted.',
        ];
    }
}

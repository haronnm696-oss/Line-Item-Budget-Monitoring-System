<?php

namespace App\Models;

use App\Services\ProjectFinancialService;
use App\Support\LineItemComputation;
use Illuminate\Database\Eloquent\Model;

/**
 * One R&D Form B line item: PS or MOOE, Direct or Indirect, an expense
 * group (optionally a sub-group), an optional computation and two amounts,
 * the implementing agency's counterpart and the project's funder share.
 *
 * allocated_amount is always implementing_agency_amount + funder_amount
 * (kept in sync on save), so code that reads allocated_amount keeps working.
 * A writer that sets only allocated_amount (older code, seeders) changes the
 * funder share.
 */
class LibCategory extends Model
{
    public const SOURCE_FUNDER = 'funder';
    public const SOURCE_COUNTERPART = 'implementing_agency';
    public const SOURCES = [self::SOURCE_FUNDER, self::SOURCE_COUNTERPART];

    /** funding source -> amount column. */
    public const SOURCE_COLUMN = [
        self::SOURCE_FUNDER => 'funder_amount',
        self::SOURCE_COUNTERPART => 'implementing_agency_amount',
    ];

    public const COST_TYPES = ['Direct', 'Indirect'];

    protected $fillable = [
        'project_id', 'category_type', 'cost_type', 'expense_group', 'expense_subgroup',
        'line_item_name', 'line_item_code', 'allocated_amount',
        'quantity', 'unit_description', 'rate', 'rate_period', 'periods',
        'implementing_agency_amount', 'funder_amount',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'implementing_agency_amount' => 'decimal:2',
        'funder_amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'quantity' => 'integer',
        'periods' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->cost_type = $item->cost_type ?: 'Direct';
            if (trim((string) $item->expense_group) === '') {
                $item->expense_group = $item->line_item_name;
            }
            $counterpart = round((float) $item->implementing_agency_amount, 2);
            if ($item->isDirty(['funder_amount', 'implementing_agency_amount']) || ! $item->isDirty('allocated_amount')) {
                $item->allocated_amount = round($counterpart + (float) $item->funder_amount, 2);
            } else {
                $item->funder_amount = round((float) $item->allocated_amount - $counterpart, 2);
            }
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    public function purchaseRequests()
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /** Allocated amount of one funding source. */
    public function amountFor(string $source): float
    {
        return round((float) $this->{self::SOURCE_COLUMN[$source] ?? 'funder_amount'}, 2);
    }

    /** The "Funded by" choice is offered only when the item has a counterpart. */
    public function hasCounterpart(): bool
    {
        return (float) $this->implementing_agency_amount > 0;
    }

    /**
     * The funding source a charge to this line item draws from: the funder
     * unless the counterpart is asked for, which needs a counterpart amount.
     */
    public function resolveFundingSource(?string $requested): string
    {
        if ($requested === null || $requested === '' || $requested === self::SOURCE_FUNDER) {
            return self::SOURCE_FUNDER;
        }
        if (! in_array($requested, self::SOURCES, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['funding_source' => ['Choose who funds this charge.']]);
        }
        if (! $this->hasCounterpart()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['funding_source' => [
                "\"{$this->line_item_name}\" has no counterpart amount; charge it to the funder.",
            ]]);
        }

        return $requested;
    }

    /** The funder amount the computation gives (quantity x rate x periods), or null. */
    public function computedAmount(): ?float
    {
        return LineItemComputation::amount($this->attributesToArray());
    }

    public function computationText(): ?string
    {
        return LineItemComputation::describe($this->attributesToArray());
    }

    /** Spent on this line item: verified expenses + verified payroll charged to it. */
    public function spentAmount(): float
    {
        return round((float) ProjectFinancialService::onlySpent($this->expenses())->sum('amount')
            + (float) ProjectFinancialService::onlySpentPayroll($this->payrolls())->sum('gross_pay'), 2);
    }

    public function remainingAmount(): float
    {
        return (float) $this->allocated_amount - $this->spentAmount();
    }

    /** Would charging $amount more push this line item over its allocation? */
    public function wouldExceedAllocation(float $amount): bool
    {
        return ($this->spentAmount() + $amount) > (float) $this->allocated_amount;
    }
}

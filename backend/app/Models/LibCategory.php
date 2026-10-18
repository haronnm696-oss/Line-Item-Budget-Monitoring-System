<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibCategory extends Model
{
    protected $fillable = [
        'project_id', 'category_type', 'line_item_name',
        'line_item_code', 'allocated_amount',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

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

    /** Verified expenses + approved/released payroll charged to this line item. */
    public function spentAmount(): float
    {
        $expenseSpent = $this->expenses()->where('status', 'verified')->sum('amount');
        $payrollSpent = $this->payrolls()->whereIn('status', ['approved', 'released'])->sum('net_pay');

        return (float) ($expenseSpent + $payrollSpent);
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    protected $fillable = [
        'project_id', 'lib_category_id', 'personnel_id', 'computed_by',
        'pay_month', 'pay_year', 'basic_rate', 'gross_pay',
        'total_deductions', 'net_pay', 'status',
    ];

    protected $casts = [
        'basic_rate' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function libCategory()
    {
        return $this->belongsTo(LibCategory::class);
    }

    public function personnel()
    {
        return $this->belongsTo(User::class, 'personnel_id');
    }

    public function computedBy()
    {
        return $this->belongsTo(User::class, 'computed_by');
    }

    public function deductions()
    {
        return $this->hasMany(PayrollDeduction::class);
    }

    /** Recompute total_deductions and net_pay from related deduction rows. */
    public function recalculate(): void
    {
        $this->total_deductions = $this->deductions()->sum('amount');
        $this->net_pay = $this->gross_pay - $this->total_deductions;
        $this->save();
    }
}

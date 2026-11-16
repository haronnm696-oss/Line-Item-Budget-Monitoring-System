<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * One person's pay on a project for one month, in that month's
 * PayrollBatch. The batch carries the workflow (draft -> submitted ->
 * endorsed -> verified, or returned); the row's workflow columns follow the
 * batch. Payroll is not part of LIB spent / utilization.
 */
class Payroll extends Model
{
    /** workflow_status -> the existing status enum (draft / approved / released). */
    public const STATUS_FOR_WORKFLOW = [
        'draft' => 'draft',
        'submitted' => 'draft',
        'endorsed' => 'draft',
        'returned' => 'draft',
        'verified' => 'approved',
    ];

    /** status -> workflow_status for rows written with a status only. */
    public const WORKFLOW_FOR_STATUS = [
        'draft' => 'draft',
        'approved' => 'verified',
        'released' => 'verified',
    ];

    protected static function booted()
    {
        // Deactivation must stop new payroll entries while preserving history.
        static::creating(function (Payroll $payroll) {
            $personnel = User::find($payroll->personnel_id);

            if ($personnel && ! $personnel->is_active) {
                throw ValidationException::withMessages([
                    'personnel_id' => 'Deactivated users cannot receive new payroll entries.',
                ]);
            }
        });

        static::saving(function (Payroll $payroll) {
            if ($payroll->workflow_status === null) {
                $payroll->workflow_status = self::WORKFLOW_FOR_STATUS[$payroll->status ?? 'draft'] ?? 'draft';
            }
        });
    }

    protected $fillable = [
        'project_id', 'payroll_batch_id', 'lib_category_id', 'personnel_id', 'computed_by',
        'pay_month', 'pay_year', 'basic_rate', 'allowance', 'gross_pay',
        'total_deductions', 'net_pay', 'status',
        'workflow_status', 'submitted_at', 'pl_reviewed_by', 'pl_reviewed_at',
        'verified_by', 'verified_at', 'return_reason',
    ];

    protected $casts = [
        'basic_rate' => 'decimal:2',
        'allowance' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'submitted_at' => 'datetime',
        'pl_reviewed_at' => 'datetime',
        'verified_at' => 'datetime',
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

    public function plReviewedBy()
    {
        return $this->belongsTo(User::class, 'pl_reviewed_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function deductions()
    {
        return $this->hasMany(PayrollDeduction::class);
    }

    public function batch()
    {
        return $this->belongsTo(PayrollBatch::class, 'payroll_batch_id');
    }

    /** "2026-11" */
    public function monthKey(): string
    {
        return sprintf('%04d-%02d', $this->pay_year, $this->pay_month);
    }

    /** Recompute total_deductions and net_pay from related deduction rows. */
    public function recalculate(): void
    {
        $this->total_deductions = $this->deductions()->sum('amount');
        $this->net_pay = $this->gross_pay - $this->total_deductions;
        $this->save();
    }
}

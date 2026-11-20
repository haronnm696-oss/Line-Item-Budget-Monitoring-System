<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * One person's honorarium on a project for one pay period, in that period's
 * PayrollBatch (pay_year / pay_month = the period's first month). Amount =
 * monthly honorarium x months the appointment is effective in the period;
 * project honoraria have no deductions and no allowances, so net = gross.
 * allowance / total_deductions / deductions() are kept only for rows
 * recorded before that rule (verified batches stay as recorded).
 * The batch carries the workflow (draft -> submitted ->
 * endorsed -> verified, or returned); the row's workflow columns follow the
 * batch. Rows of a verified batch are spent (gross pay, charged to the
 * row's PS line item from the funder's share): ProjectFinancialService.
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
        'pay_month', 'pay_year', 'basic_rate', 'months', 'computation', 'allowance', 'gross_pay',
        'total_deductions', 'net_pay', 'status',
        'workflow_status', 'submitted_at', 'pl_reviewed_by', 'pl_reviewed_at',
        'verified_by', 'verified_at', 'return_reason',
    ];

    protected $casts = [
        'basic_rate' => 'decimal:2',
        'months' => 'integer',
        'computation' => 'array',
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

    /** Months paid (older rows: 1). */
    public function monthsPaid(): int
    {
        return $this->months ?? 1;
    }

    /**
     * The computation segments: [{honorarium, months}], one per rate (two
     * when the honorarium changed inside the period). Older rows: one month
     * at the basic rate.
     *
     * @return array<int, array{honorarium: float, months: int}>
     */
    public function segments(): array
    {
        $segments = is_array($this->computation) ? $this->computation : [];
        if ($segments === []) {
            return [['honorarium' => (float) $this->basic_rate, 'months' => $this->monthsPaid()]];
        }

        return array_map(fn ($s) => ['honorarium' => (float) ($s['honorarium'] ?? 0), 'months' => (int) ($s['months'] ?? 0)], $segments);
    }

    /** True for a row recorded with an allowance or deductions (before they were removed). */
    public function hasLegacyAmounts(): bool
    {
        return (float) $this->allowance > 0 || (float) $this->total_deductions > 0;
    }

    /** "(8,800 x 3 months) = 26,400.00"; older rows with an allowance say so. */
    public function computationText(): string
    {
        $parts = array_map(
            fn (array $s) => '(' . self::rate($s['honorarium']) . ' x ' . $s['months'] . ' ' . ($s['months'] === 1 ? 'month' : 'months') . ')',
            $this->segments()
        );
        if ((float) $this->allowance > 0) {
            $parts[] = 'allowance ' . number_format((float) $this->allowance, 2);
        }

        return implode(' + ', $parts) . ' = ' . number_format((float) $this->gross_pay, 2);
    }

    /** 8800 -> "8,800"; 8800.5 -> "8,800.50". */
    public static function rate(float $value): string
    {
        return number_format($value, fmod($value, 1.0) == 0.0 ? 0 : 2);
    }
}

<?php

namespace App\Models;

use App\Models\Contracts\HasDocuments;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One project's payroll for one month. Submit, approve / return and
 * verify / reject act on the whole batch; its rows (Payroll) can be edited
 * only while the batch is draft or returned. Payroll files attach here.
 */
class PayrollBatch extends Model implements HasDocuments
{
    public const EDITABLE = ['draft', 'returned'];

    protected $fillable = [
        'project_id', 'pay_year', 'pay_month', 'prepared_by',
        'workflow_status', 'submitted_at', 'submitted_by', 'pl_reviewed_by', 'pl_reviewed_at',
        'verified_by', 'verified_at', 'return_reason', 'approval_request_id',
    ];

    protected $casts = [
        'pay_year' => 'integer',
        'pay_month' => 'integer',
        'submitted_at' => 'datetime',
        'pl_reviewed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function plReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pl_reviewed_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** The latest escalation of a step to the Program Leader, if any. */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /** The pending escalation (approve or verify step), if any. */
    public function pendingEscalation(): ?ApprovalRequest
    {
        $approval = $this->approvalRequest;

        return $approval && $approval->isPending() ? $approval : null;
    }

    /** People paid in this batch. */
    public function paidUserIds(): array
    {
        return $this->rows()->pluck('personnel_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isEditable(): bool
    {
        return in_array($this->workflow_status, self::EDITABLE, true);
    }

    /** "2026-11" */
    public function monthKey(): string
    {
        return sprintf('%04d-%02d', $this->pay_year, $this->pay_month);
    }

    /** "November 2026" */
    public function monthLabel(): string
    {
        return Carbon::create($this->pay_year, $this->pay_month, 1)->format('F Y');
    }

    /** Everyone who prepared or computed this payroll (the makers). */
    public function makerIds(): array
    {
        return collect([$this->prepared_by, $this->submitted_by])
            ->merge($this->rows()->pluck('computed_by'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function documentRules(): array
    {
        return [
            'folder' => 'payroll-batches',
            'types' => ['payroll_file', 'other'],
            'uploader_roles' => ['research-assistant', 'finance-admin'],
            'uploader_message' => 'Only a Research Assistant or Finance/Admin staff of this project can attach payroll files.',
            'open' => $this->isEditable(),
            'closed_message' => 'Payroll files can be attached only while the payroll is a draft or returned.',
            'locked' => $this->workflow_status === 'verified',
            'locked_message' => 'Files of a verified payroll cannot be deleted.',
        ];
    }
}

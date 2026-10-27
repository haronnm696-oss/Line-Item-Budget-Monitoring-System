<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    public const TYPES = [
        'disbursement' => 'Fund Release',
        'budget_realignment' => 'Budget Realignment',
        'payroll' => 'Payroll',
    ];

    protected $fillable = [
        'project_id', 'project_tranche_id', 'type', 'title', 'description',
        'amount', 'payload', 'requested_by', 'reviewed_by', 'status',
        'review_notes', 'reviewed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tranche(): BelongsTo
    {
        return $this->belongsTo(ProjectTranche::class, 'project_tranche_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}

<?php

namespace App\Models;

use App\Models\Contracts\HasDocuments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

class ApprovalRequest extends Model implements HasDocuments
{
    public const TYPES = [
        'disbursement' => 'Fund Release',
        'budget_realignment' => 'Budget Realignment',
        'payroll' => 'Payroll',
        'liquidation' => 'Liquidation',
    ];

    protected $fillable = [
        'project_id', 'project_tranche_id', 'liquidation_id', 'type', 'title', 'description',
        'justification', 'amount', 'payload', 'requested_by', 'reviewed_by',
        'reviewed_by_name', 'reviewed_by_role', 'status', 'review_notes', 'reviewed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $approval) {
            if (blank($approval->justification)) {
                throw ValidationException::withMessages([
                    'justification' => ['A justification is required for new approval requests.'],
                ]);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tranche(): BelongsTo
    {
        return $this->belongsTo(ProjectTranche::class, 'project_tranche_id');
    }

    public function liquidation(): BelongsTo
    {
        return $this->belongsTo(Liquidation::class);
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

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** Supporting documents (liquidation): added by the requester while pending. */
    public function documentRules(): array
    {
        return [
            'folder' => 'approval-requests',
            'types' => Document::TYPES,
            'uploader_ids' => array_filter([(int) $this->requested_by]),
            'uploader_message' => 'Only the person who made this request can attach documents.',
            'open' => $this->isPending(),
            'closed_message' => 'Documents can be attached only while the request is pending.',
            'locked' => ! $this->isPending(),
            'locked_message' => 'Documents of a reviewed request cannot be deleted.',
        ];
    }
}

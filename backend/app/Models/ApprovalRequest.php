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
        'withdrawn_at', 'withdrawn_by', 'resubmitted_at', 'last_return_reason',
    ];

    /**
     * Stored status of a Program Leader "Return". The enum keeps its original
     * value `rejected`; it is shown, filtered and handled as "returned" (the
     * maker may edit and resubmit, or withdraw). Legacy rejected rows are
     * therefore returned requests too.
     */
    public const STORED_RETURNED = 'rejected';

    /** API / UI status => label. */
    public const STATUS_LABELS = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'returned' => 'Returned',
        'withdrawn' => 'Withdrawn',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'reviewed_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'resubmitted_at' => 'datetime',
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

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Returned to the maker and not withdrawn: it may be edited and resubmitted, or withdrawn. */
    public function isReturned(): bool
    {
        return $this->status === self::STORED_RETURNED && $this->withdrawn_at === null;
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STORED_RETURNED && $this->withdrawn_at !== null;
    }

    /** pending | approved | returned | withdrawn (see STORED_RETURNED). */
    public function displayStatus(): string
    {
        if ($this->isWithdrawn()) {
            return 'withdrawn';
        }

        return $this->status === self::STORED_RETURNED ? 'returned' : (string) $this->status;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->displayStatus()] ?? ucfirst((string) $this->status);
    }

    /** When it last went to the Program Leader (resubmission, else creation). */
    public function sentAt()
    {
        return $this->resubmitted_at ?? $this->created_at;
    }

    /** Pending, or returned to the maker: its documents may still change. */
    public function isOpenForDocuments(): bool
    {
        return $this->isPending() || $this->isReturned();
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
            'open' => $this->isOpenForDocuments(),
            'closed_message' => 'Documents can be attached only while the request is pending or returned.',
            'locked' => ! $this->isOpenForDocuments(),
            'locked_message' => 'Documents of a reviewed request cannot be deleted.',
        ];
    }
}

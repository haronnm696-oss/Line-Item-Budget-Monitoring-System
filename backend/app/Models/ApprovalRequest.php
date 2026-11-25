<?php

namespace App\Models;

use App\Models\Contracts\HasDocuments;
use App\Support\ReleaseWording;
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
        // An expense no Finance/Admin of the project may verify (escalated).
        'expense' => 'Expense verification',
    ];

    /**
     * Type filter of the Program Leader approvals list: filter value =>
     * [stored type, option label]. The single list of filter types: the
     * list endpoint validates against it and returns it as `types`, and the
     * frontend renders its Type options from that. Every key of TYPES must
     * appear here (tests check it).
     */
    public const FILTER_TYPES = [
        'fund-release' => ['type' => 'disbursement', 'label' => 'Fund release'],
        'realignment' => ['type' => 'budget_realignment', 'label' => 'Realignment'],
        'liquidation' => ['type' => 'liquidation', 'label' => 'Liquidation'],
        'payroll' => ['type' => 'payroll', 'label' => 'Payroll'],
        'expense' => ['type' => 'expense', 'label' => 'Expense verification'],
    ];

    /**
     * Values the Type filter accepts: "all", the filter values, and the raw
     * stored type slugs (older links).
     *
     * @return array<int, string>
     */
    public static function filterTypeValues(): array
    {
        return array_values(array_unique(array_merge(
            ['all'],
            array_keys(self::FILTER_TYPES),
            array_keys(self::TYPES)
        )));
    }

    /** Stored type for a Type filter value (a raw stored slug maps to itself). */
    public static function storedTypeForFilter(string $value): string
    {
        return self::FILTER_TYPES[$value]['type'] ?? $value;
    }

    /**
     * Type filter options for the frontend, in display order.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function filterTypeOptions(): array
    {
        $options = [];
        foreach (self::FILTER_TYPES as $value => $type) {
            $options[] = ['value' => $value, 'label' => $type['label']];
        }

        return $options;
    }

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

    /**
     * The title users read. A fund release is named by its release
     * ("Release 2", its stored number; the "Fund release" type badge sits
     * beside it), whatever title was stored ("Fund release: Second tranche"
     * on older rows). A liquidation reads its liquidation's title. Other
     * text goes through ReleaseWording, which gives an old number reference
     * the linked release's stored number.
     */
    public function displayTitle(): string
    {
        $release = $this->project_tranche_id ? $this->tranche : null;
        if ($this->type === 'disbursement' && $release) {
            return $release->releaseLabel();
        }
        if ($this->type === 'liquidation' && $this->liquidation_id && $this->liquidation?->title) {
            return (string) ReleaseWording::apply($this->liquidation->title, $release ?? $this->liquidation->tranche);
        }
        $title = (string) ReleaseWording::apply((string) $this->title, $release);
        if ($this->type === 'disbursement') {
            $title = preg_replace('/^Fund release:\s*/i', '', $title) ?: $title;
        }

        return $title;
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

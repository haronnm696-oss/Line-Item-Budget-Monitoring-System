<?php

namespace App\Models;

use App\Models\Contracts\HasDocuments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A purchase request against a LIB line item. Workflow: draft -> submitted
 * -> endorsed ("Approved by Project Leader") or returned. No Finance step.
 */
class PurchaseRequest extends Model implements HasDocuments
{
    use SoftDeletes;

    /** Document types that count as the quotation / invoice a submit needs. */
    public const SUPPORTING_TYPES = ['quotation', 'invoice'];

    protected $fillable = [
        'project_id', 'lib_category_id', 'requested_by', 'description', 'quantity', 'estimated_amount',
        'workflow_status', 'submitted_at', 'pl_reviewed_by', 'pl_reviewed_at', 'return_reason',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'estimated_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'pl_reviewed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function libCategory(): BelongsTo
    {
        return $this->belongsTo(LibCategory::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function plReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pl_reviewed_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function supportingDocuments(): MorphMany
    {
        return $this->documents()->whereIn('document_type', self::SUPPORTING_TYPES);
    }

    public function documentRules(): array
    {
        return [
            'folder' => 'purchase-requests',
            'types' => ['quotation', 'invoice', 'other'],
            'uploader_roles' => ['research-assistant', 'finance-admin'],
            'uploader_message' => 'Only a Research Assistant or Finance/Admin staff of this project can attach documents.',
            'open' => in_array($this->workflow_status, ['draft', 'returned'], true),
            'closed_message' => 'Documents can be attached only while the purchase request is a draft or returned.',
            'locked' => $this->workflow_status === 'endorsed',
            'locked_message' => 'Documents of an approved purchase request cannot be deleted.',
        ];
    }
}

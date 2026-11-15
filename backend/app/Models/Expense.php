<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    /** workflow_status -> the existing status enum ('verified' is the only spent status). */
    public const STATUS_FOR_WORKFLOW = [
        'draft' => 'pending',
        'submitted' => 'pending',
        'endorsed' => 'pending',
        'returned' => 'rejected',
        'verified' => 'verified',
    ];

    /** status -> workflow_status for rows written with a status only. */
    public const WORKFLOW_FOR_STATUS = [
        'pending' => 'submitted',
        'verified' => 'verified',
        'rejected' => 'returned',
    ];

    protected $fillable = [
        'project_id', 'lib_category_id', 'recorded_by', 'verified_by',
        'reference_no', 'payee', 'description', 'amount', 'expense_date',
        'attachment_path', 'status', 'remarks', 'verified_at',
        'workflow_status', 'submitted_at', 'pl_reviewed_by', 'pl_reviewed_at', 'return_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'verified_at' => 'datetime',
        'submitted_at' => 'datetime',
        'pl_reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Rows written with a status only (seeders, older code) get the
        // matching workflow status, so the two never disagree.
        static::saving(function (self $expense) {
            if ($expense->workflow_status === null) {
                $expense->workflow_status = self::WORKFLOW_FOR_STATUS[$expense->status ?? 'pending'] ?? 'submitted';
            }
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function libCategory()
    {
        return $this->belongsTo(LibCategory::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function plReviewedBy()
    {
        return $this->belongsTo(User::class, 'pl_reviewed_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function receipts(): MorphMany
    {
        return $this->documents()->where('document_type', Document::TYPE_RECEIPT);
    }
}

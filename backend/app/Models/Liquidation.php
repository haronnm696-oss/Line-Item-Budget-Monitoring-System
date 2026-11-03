<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Liquidation extends Model
{
    protected $fillable = [
        'project_id', 'project_tranche_id', 'title', 'amount', 'due_date',
        'submitted_at', 'status', 'document_path', 'remarks',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'submitted_at' => 'date',
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

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvalRequest(): HasOne
    {
        return $this->hasOne(ApprovalRequest::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}

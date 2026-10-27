<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTranche extends Model
{
    protected $fillable = [
        'project_id', 'sequence', 'name', 'amount', 'percentage',
        'status', 'planned_release_date', 'released_at', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'planned_release_date' => 'date',
        'released_at' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function isReleased(): bool
    {
        return $this->status === 'released';
    }
}

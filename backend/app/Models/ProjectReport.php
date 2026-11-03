<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectReport extends Model
{
    public const TYPES = [
        'progress' => 'Progress report',
        'terminal' => 'Terminal report',
        'financial' => 'Financial report',
    ];

    protected $fillable = [
        'project_id', 'type', 'title', 'due_date', 'submitted_at', 'document_path',
    ];

    protected $casts = [
        'due_date' => 'date',
        'submitted_at' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function isOverdue(): bool
    {
        return $this->submitted_at === null && $this->due_date?->isBefore(now()->startOfDay());
    }
}

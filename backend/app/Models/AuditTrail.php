<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class AuditTrail extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'user_id', 
        'action', 
        'model_type', 
        'model_id',
        'old_values', 
        'new_values', 
        'reason',
        'ip_address', 
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function log(string $action, Model $model, ?array $oldValues = null, ?array $newValues = null): self
    {
        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => class_basename($model),
            'model_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Record an administrative change with an explicit actor and reason.
     * Used by Program Leader user management so the log is complete even when
     * the action runs inside a queued/background context.
     */
    public static function record(
        User $actor,
        string $action,
        Model $model,
        array $oldValues = [],
        array $newValues = [],
        ?string $reason = null
    ): self {
        return self::create([
            'user_id' => $actor->id,
            'action' => $action,
            'model_type' => class_basename($model),
            'model_id' => $model->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'reason' => $reason,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}

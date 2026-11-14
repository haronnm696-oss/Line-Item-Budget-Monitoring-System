<?php

namespace App\Models;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One audit entry. Entries are append-only: there is no route that edits or
 * deletes them, and the model refuses updates and deletes.
 */
class AuditTrail extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'project_id',
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

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            // Never store passwords, tokens or other secrets, whoever writes.
            if (is_array($entry->old_values)) {
                $entry->old_values = AuditLogger::withoutSecrets($entry->old_values) ?: null;
            }
            if (is_array($entry->new_values)) {
                $entry->new_values = AuditLogger::withoutSecrets($entry->new_values) ?: null;
            }

            if ($entry->project_id === null) {
                $entry->project_id = AuditLogger::resolveProjectId(
                    $entry->model_type,
                    $entry->model_id,
                    $entry->old_values,
                    $entry->new_values
                );
            }
        });

        static::updating(function () {
            throw new LogicException('Audit entries are read-only.');
        });

        static::deleting(function () {
            throw new LogicException('Audit entries are read-only.');
        });
    }

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
            'project_id' => AuditLogger::projectIdOf($model),
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
            'project_id' => AuditLogger::projectIdOf($model) ?? AuditLogger::resolveProjectId(class_basename($model), $model->getKey(), $oldValues, $newValues),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'reason' => $reason,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}

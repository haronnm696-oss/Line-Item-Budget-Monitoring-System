<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Single place that writes audit entries.
 *
 * Model observers (AuditObserver) log add / edit / delete for the financial
 * and account records automatically. Code paths that already write a more
 * specific entry (an approval, a registration decision, a role change) run
 * inside AuditLogger::muted() so the same change is not logged twice.
 */
class AuditLogger
{
    /** Keys never written to (or shown from) the log. */
    private const SECRET_PATTERN = '/password|token|secret|remember/i';

    /** Record types whose table carries a project_id column. */
    public const PROJECT_TABLES = [
        'ApprovalRequest' => 'approval_requests',
        'Expense' => 'expenses',
        'LibCategory' => 'lib_categories',
        'ProjectTranche' => 'project_tranches',
        'Payroll' => 'payrolls',
        'Liquidation' => 'liquidations',
        'ProjectReport' => 'project_reports',
        'ProjectUser' => 'project_user',
        'ProjectPersonnel' => 'project_personnel',
    ];

    private static int $muted = 0;

    /**
     * Run $callback without automatic (observer) entries. Explicit entries
     * written inside it are still recorded.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function muted(callable $callback)
    {
        self::$muted++;

        try {
            return $callback();
        } finally {
            self::$muted--;
        }
    }

    /**
     * DB::transaction() for code that writes its own, more specific audit
     * entry (approvals, account decisions): observers stay quiet inside.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback)
    {
        return self::muted(fn () => DB::transaction($callback));
    }

    public static function isMuted(): bool
    {
        return self::$muted > 0;
    }

    /**
     * Write one entry. For "updated" only fields whose value changed are kept.
     */
    public static function write(
        string $action,
        Model $model,
        array $oldValues = [],
        array $newValues = [],
        ?User $actor = null,
        ?string $reason = null
    ): ?AuditTrail {
        $oldValues = self::withoutSecrets($oldValues);
        $newValues = self::withoutSecrets($newValues);

        if ($action === 'updated') {
            [$oldValues, $newValues] = self::onlyChanged($oldValues, $newValues);
            if ($oldValues === [] && $newValues === []) {
                return null;
            }
        }

        return AuditTrail::create([
            'user_id' => $actor?->id ?? auth()->id(),
            'action' => $action,
            'model_type' => class_basename($model),
            'model_id' => $model->getKey(),
            'project_id' => self::projectIdOf($model),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{0: array, 1: array}
     */
    public static function onlyChanged(array $old, array $new): array
    {
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $changedOld = [];
        $changedNew = [];

        foreach ($keys as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;
            if (self::sameValue($before, $after)) {
                continue;
            }
            if (array_key_exists($key, $old)) {
                $changedOld[$key] = $before;
            }
            if (array_key_exists($key, $new)) {
                $changedNew[$key] = $after;
            }
        }

        return [$changedOld, $changedNew];
    }

    public static function withoutSecrets(?array $values): array
    {
        $clean = [];
        foreach ($values ?? [] as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_PATTERN, $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::withoutSecrets($value) : $value;
        }

        return $clean;
    }

    public static function projectIdOf(Model $model): ?int
    {
        if ($model instanceof Project) {
            return (int) $model->getKey();
        }

        $projectId = $model->getAttribute('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    /**
     * Project id for an entry written without one (direct AuditTrail::create
     * calls): the logged values first, then the record's own row.
     */
    public static function resolveProjectId(?string $modelType, $modelId, ?array $old, ?array $new): ?int
    {
        if ($modelType === 'Project' && $modelId) {
            return (int) $modelId;
        }

        foreach ([$new, $old] as $values) {
            if (is_array($values) && isset($values['project_id']) && is_numeric($values['project_id'])) {
                return (int) $values['project_id'];
            }
        }

        $table = self::PROJECT_TABLES[$modelType] ?? null;
        if ($table && $modelId) {
            $projectId = DB::table($table)->where('id', $modelId)->value('project_id');

            return $projectId !== null ? (int) $projectId : null;
        }

        return null;
    }

    private static function sameValue($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b && ($a !== null) === ($b !== null);
        }

        return $a === $b;
    }
}

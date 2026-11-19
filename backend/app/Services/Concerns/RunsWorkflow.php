<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared steps of the record workflows (expenses, payroll, purchase
 * requests). Every step checks, in order: role on the project (403), maker
 * != checker (403), allowed transition (422); then changes the record under
 * a row lock with exactly one audit entry, and notifies after the commit.
 */
trait RunsWorkflow
{
    /** Labels of the workflow stages, used in "cannot be X while it is Y". */
    abstract protected function stageLabels(): array;

    protected function requireRole(?Project $project, User $actor, array $slugs, string $message): void
    {
        if (! $project || ! $actor->hasRoleOn($project, $slugs)) {
            abort(403, $message);
        }
    }

    protected function requireNotSame($userId, User $actor, string $message): void
    {
        if ($userId !== null && (int) $userId === (int) $actor->id) {
            abort(403, $message);
        }
    }

    protected function requireState(Model $record, array $allowed, string $verb): void
    {
        $stage = $record->getAttribute('workflow_status');
        if (! in_array($stage, $allowed, true)) {
            $label = strtolower($this->stageLabels()[$stage] ?? (string) $stage);
            throw ValidationException::withMessages([
                'workflow_status' => ["This {$this->noun()} cannot be {$verb} while it is {$label}."],
            ]);
        }
    }

    protected function requireReason(string $reason, string $message): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [$message]]);
        }

        return $reason;
    }

    /** The record as stored, locked for the rest of the transaction. */
    protected function lockRecord(Model $record): Model
    {
        return $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Move to a stage (plus extra fields) with one audit entry. The state is
     * checked again under the lock. $sync maps a stage to the legacy
     * `status` column, when the record has one.
     */
    protected function moveRecord(Model $record, string $to, User $actor, string $auditAction, array $from, string $verb, array $extra, ?string $reason = null, ?array $sync = null): Model
    {
        return AuditLogger::transaction(function () use ($record, $to, $actor, $auditAction, $from, $verb, $extra, $reason, $sync) {
            $locked = $this->lockRecord($record);
            $this->requireState($locked, $from, $verb);

            $changes = array_merge($extra, ['workflow_status' => $to]);
            if ($sync !== null) {
                $changes['status'] = $sync[$to];
            }

            $old = $locked->only(array_keys($changes));
            $locked->update($changes);
            $new = $locked->fresh()->only(array_keys($old));

            AuditLogger::write($auditAction, $locked, $old, $new, $actor, $reason);

            return $locked->fresh();
        });
    }

    /** Active members of a project holding a role on it. */
    protected function projectMembers(int $projectId, string $roleSlug): Collection
    {
        return ProjectUser::query()
            ->where('project_id', $projectId)
            ->whereHas('role', fn ($q) => $q->where('slug', $roleSlug))
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn (?User $user) => $user && $user->is_active)
            ->unique('id')
            ->values();
    }

    /**
     * A project has one Project Leader, and the maker never approves their
     * own item: when the maker holds the Project Leader role on the project
     * (also in older data where they hold another role there too), nobody
     * is left for the Project Leader step, so the workflow routes around it.
     */
    protected function makerLeadsProject(?int $projectId, $makerId): bool
    {
        if (! $projectId || ! $makerId) {
            return false;
        }

        return ProjectUser::query()
            ->where('project_id', $projectId)
            ->where('user_id', $makerId)
            ->whereHas('role', fn ($q) => $q->where('slug', 'project-leader'))
            ->exists();
    }

    /** The user with this id, when active. */
    protected function activeUser($userId): Collection
    {
        $user = $userId ? User::find($userId) : null;

        return collect($user && $user->is_active ? [$user] : []);
    }

    /** One notification per recipient (never the actor). A failure never undoes the change. */
    protected function notifyUsers(Collection $recipients, BaseNotification $notification, User $actor): void
    {
        $recipients = $recipients->reject(fn (User $user) => (int) $user->id === (int) $actor->id);
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, $notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** "expense", "payroll entry", ... used in messages. */
    abstract protected function noun(): string;
}

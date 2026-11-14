<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs add / edit / delete for audited records (registered in
 * AppServiceProvider). Only changed fields are stored for edits.
 */
class AuditObserver
{
    /** Bookkeeping columns that never make an edit worth logging. */
    private const IGNORED = ['created_at', 'updated_at', 'last_login_at', 'remember_token', 'password'];

    public function created(Model $model): void
    {
        if (AuditLogger::isMuted()) {
            return;
        }

        AuditLogger::write('created', $model, [], $this->clean($model->getAttributes()), $this->actorFor($model));
    }

    public function updated(Model $model): void
    {
        if (AuditLogger::isMuted()) {
            return;
        }

        $changes = $this->clean($model->getChanges());
        if ($changes === []) {
            return;
        }

        // A field that had no value before still gets an (empty) old value.
        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $model->getRawOriginal($key);
        }
        AuditLogger::write('updated', $model, $old, $changes, $this->actorFor($model));
    }

    public function deleted(Model $model): void
    {
        if (AuditLogger::isMuted()) {
            return;
        }

        AuditLogger::write('deleted', $model, $this->clean($model->getRawOriginal()), [], $this->actorFor($model));
    }

    private function clean(array $values): array
    {
        return array_diff_key($values, array_flip(self::IGNORED));
    }

    /** The signed-in user; a self-registration is attributed to the new account. */
    private function actorFor(Model $model): ?User
    {
        $actor = auth()->user();
        if ($actor instanceof User) {
            return $actor;
        }

        return $model instanceof User && $model->wasRecentlyCreated ? $model : null;
    }
}

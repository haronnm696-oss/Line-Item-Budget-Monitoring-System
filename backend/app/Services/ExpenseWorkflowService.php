<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ExpenseWorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The one place that moves an expense through its workflow:
 *
 *   draft --submit--> submitted --endorse--> endorsed --verify--> verified
 *     ^                   |                     |
 *     |                 return               reject
 *     +---- returned <----+---------------------+
 *
 * Roles come from the person's role ON THAT PROJECT. Every step checks, in
 * order: role (403), maker != checker (403), allowed transition (422). The
 * old `status` column is kept in sync (Expense::STATUS_FOR_WORKFLOW);
 * status = verified is still the only thing that counts as spent.
 * Each step writes exactly one audit entry; hand-offs notify the next person
 * after the change is committed.
 */
class ExpenseWorkflowService
{
    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'endorsed' => 'Approved by Project Leader',
        'returned' => 'Returned',
        'verified' => 'Verified',
    ];

    private const ENCODERS = ['research-assistant', 'finance-admin'];

    // ---------------------------------------------------------------- create / edit

    public function create(Project $project, array $data, User $actor): Expense
    {
        $this->requireRole($project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can record expenses.');

        return AuditLogger::transaction(function () use ($project, $data, $actor) {
            $expense = Expense::create(array_merge($this->only($data), [
                'project_id' => $project->id,
                'recorded_by' => $actor->id,
                'workflow_status' => 'draft',
                'status' => Expense::STATUS_FOR_WORKFLOW['draft'],
            ]));

            AuditLogger::write('created', $expense, [], $this->snapshot($expense), $actor);

            return $expense->fresh();
        });
    }

    public function update(Expense $expense, array $data, User $actor): Expense
    {
        $this->requireRole($expense->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can edit expenses.');
        $this->requireState($expense, ['draft', 'returned'], 'edited');

        return AuditLogger::transaction(function () use ($expense, $data, $actor) {
            $locked = $this->lock($expense);
            $this->requireState($locked, ['draft', 'returned'], 'edited');
            $old = $this->snapshot($locked);
            $locked->update($this->only($data));
            AuditLogger::write('updated', $locked, $old, $this->snapshot($locked->fresh()), $actor);

            return $locked->fresh();
        });
    }

    public function delete(Expense $expense, User $actor): void
    {
        $this->requireRole($expense->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can delete expenses.');
        if ((int) $expense->recorded_by !== (int) $actor->id) {
            abort(403, 'You can only delete expenses you recorded.');
        }
        $this->requireState($expense, ['draft', 'returned'], 'deleted');

        AuditLogger::transaction(function () use ($expense, $actor) {
            $locked = $this->lock($expense);
            $this->requireState($locked, ['draft', 'returned'], 'deleted');
            $old = $this->snapshot($locked);
            $locked->delete(); // soft delete: the row and its history stay
            AuditLogger::write('deleted', $locked, $old, [], $actor);
        });
    }

    // ---------------------------------------------------------------- hand-offs

    public function submit(Expense $expense, User $actor): Expense
    {
        $this->requireRole($expense->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can submit expenses.');
        $this->requireState($expense, ['draft', 'returned'], 'submitted');

        if (! $expense->receipts()->exists()) {
            throw ValidationException::withMessages([
                'documents' => ['Attach at least one receipt before submitting.'],
            ]);
        }

        $expense = $this->move($expense, 'submitted', $actor, 'submitted', ['draft', 'returned'], [
            'submitted_at' => now(),
            'return_reason' => null,
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ]);

        $this->notify($this->projectMembers($expense->project_id, 'project-leader'), $expense, 'submitted', $actor);

        return $expense;
    }

    public function endorse(Expense $expense, User $actor): Expense
    {
        $this->requireRole($expense->project, $actor, ['project-leader'], 'Only the Project Leader of this project can approve expenses.');
        $this->requireNotMaker($expense, $actor, 'You cannot approve or return an expense you recorded.');
        $this->requireState($expense, ['submitted'], 'approved');

        $expense = $this->move($expense, 'endorsed', $actor, 'endorsed', ['submitted'], [
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ]);

        $this->notify($this->projectMembers($expense->project_id, 'finance-admin'), $expense, 'endorsed', $actor);

        return $expense;
    }

    public function returnToEncoder(Expense $expense, string $reason, User $actor): Expense
    {
        $this->requireRole($expense->project, $actor, ['project-leader'], 'Only the Project Leader of this project can return expenses.');
        $this->requireNotMaker($expense, $actor, 'You cannot approve or return an expense you recorded.');
        $this->requireState($expense, ['submitted'], 'returned');
        $reason = $this->requireReason($reason, 'A reason is required to return an expense.');

        $expense = $this->move($expense, 'returned', $actor, 'returned', ['submitted'], [
            'return_reason' => $reason,
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ], $reason);

        $this->notify($this->recorder($expense), $expense, 'returned', $actor, $reason);

        return $expense;
    }

    public function verify(Expense $expense, User $actor): Expense
    {
        $this->requireRole($expense->project, $actor, ['finance-admin'], 'Only Finance/Admin staff of this project can verify expenses.');
        $this->requireNotMaker($expense, $actor, 'You cannot verify or reject an expense you recorded.');
        $this->requireNotEndorser($expense, $actor);
        $this->requireState($expense, ['endorsed'], 'verified');

        $expense = $this->move($expense, 'verified', $actor, 'verified', ['endorsed'], [
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'remarks' => 'Verified by ' . $actor->name . '.',
        ]);

        $this->notify($this->recorder($expense), $expense, 'verified', $actor);

        return $expense;
    }

    public function reject(Expense $expense, string $reason, User $actor): Expense
    {
        $this->requireRole($expense->project, $actor, ['finance-admin'], 'Only Finance/Admin staff of this project can reject expenses.');
        $this->requireNotMaker($expense, $actor, 'You cannot verify or reject an expense you recorded.');
        $this->requireNotEndorser($expense, $actor);
        $this->requireState($expense, ['endorsed'], 'rejected');
        $reason = $this->requireReason($reason, 'A reason is required to reject an expense.');

        // Rejected by Finance/Admin = sent back to the encoder as returned.
        $expense = $this->move($expense, 'returned', $actor, 'rejected', ['endorsed'], [
            'return_reason' => $reason,
            'remarks' => $reason,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ], $reason);

        $this->notify($this->recorder($expense), $expense, 'rejected', $actor, $reason);

        return $expense;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Change the workflow status (and keep `status` in sync) with exactly one
     * audit entry. The state is checked again under a row lock.
     */
    private function move(Expense $expense, string $to, User $actor, string $auditAction, array $from, array $extra, ?string $reason = null): Expense
    {
        return AuditLogger::transaction(function () use ($expense, $to, $actor, $auditAction, $from, $extra, $reason) {
            $locked = $this->lock($expense);
            $this->requireState($locked, $from, $auditAction === 'endorsed' ? 'approved' : $auditAction);

            $old = $locked->only(array_merge(['workflow_status', 'status'], array_keys($extra)));
            $locked->update(array_merge($extra, [
                'workflow_status' => $to,
                'status' => Expense::STATUS_FOR_WORKFLOW[$to],
            ]));
            $new = $locked->fresh()->only(array_keys($old));

            AuditLogger::write($auditAction, $locked, $old, $new, $actor, $reason);

            return $locked->fresh();
        });
    }

    private function lock(Expense $expense): Expense
    {
        return Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
    }

    private function requireRole(?Project $project, User $actor, array $slugs, string $message): void
    {
        if (! $project || ! in_array($actor->roleOn($project), $slugs, true)) {
            abort(403, $message);
        }
    }

    private function requireNotMaker(Expense $expense, User $actor, string $message): void
    {
        if ((int) $expense->recorded_by === (int) $actor->id) {
            abort(403, $message);
        }
    }

    private function requireNotEndorser(Expense $expense, User $actor): void
    {
        if ($expense->pl_reviewed_by !== null && (int) $expense->pl_reviewed_by === (int) $actor->id) {
            abort(403, 'You approved this expense as Project Leader; another person must verify it.');
        }
    }

    private function requireState(Expense $expense, array $allowed, string $verb): void
    {
        if (! in_array($expense->workflow_status, $allowed, true)) {
            $label = strtolower(self::LABELS[$expense->workflow_status] ?? (string) $expense->workflow_status);
            throw ValidationException::withMessages([
                'workflow_status' => ["This expense cannot be {$verb} while it is {$label}."],
            ]);
        }
    }

    private function requireReason(string $reason, string $message): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [$message]]);
        }

        return $reason;
    }

    /** Fields a Research Assistant or Finance/Admin may set on a draft. */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'lib_category_id', 'description', 'payee', 'reference_no', 'amount', 'expense_date',
        ]));
    }

    private function snapshot(Expense $expense): array
    {
        return $expense->only([
            'project_id', 'lib_category_id', 'reference_no', 'payee', 'description', 'amount', 'expense_date', 'workflow_status',
        ]);
    }

    /** Active members of a project holding a role on it. */
    private function projectMembers(int $projectId, string $roleSlug): Collection
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

    private function recorder(Expense $expense): Collection
    {
        $user = $expense->recordedBy()->first();

        return collect($user && $user->is_active ? [$user] : []);
    }

    /** One notification per recipient (never the actor). A failure never undoes the change. */
    private function notify(Collection $recipients, Expense $expense, string $event, User $actor, ?string $reason = null): void
    {
        $recipients = $recipients->reject(fn (User $user) => (int) $user->id === (int) $actor->id);
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new ExpenseWorkflowNotification($expense->loadMissing(['project', 'libCategory']), $event, $actor, $reason));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

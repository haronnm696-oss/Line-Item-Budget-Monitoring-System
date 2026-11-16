<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\ExpenseWorkflowNotification;
use App\Services\Concerns\RunsWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
 *
 * A Project Leader may also record expenses on their own project (and edit,
 * delete, attach receipts to and submit only the ones they recorded). Their
 * submit skips the Project Leader approval step: draft -> endorsed
 * ("Waiting for Finance/Admin verification", pl_reviewed_by stays empty),
 * with the same receipt rule, one audit entry ("submitted") and a
 * notification to Finance/Admin. The recorder never verifies.
 */
class ExpenseWorkflowService
{
    use RunsWorkflow;

    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'endorsed' => 'Approved by Project Leader',
        'returned' => 'Returned',
        'verified' => 'Verified',
    ];

    /** Label of an endorsed expense that a Project Leader recorded (no approval step). */
    public const WAITING_VERIFICATION = 'Waiting for Finance/Admin verification';

    private const ENCODERS = ['research-assistant', 'finance-admin'];
    private const LEADER = 'project-leader';

    /** The stage label of one expense. */
    public static function label(Expense $expense): string
    {
        if ($expense->workflow_status === 'endorsed' && $expense->pl_reviewed_by === null) {
            return self::WAITING_VERIFICATION;
        }

        return self::LABELS[$expense->workflow_status] ?? (string) $expense->workflow_status;
    }

    // ---------------------------------------------------------------- create / edit

    public function __construct(private PurchaseRequestService $purchaseRequests)
    {
    }

    /**
     * Record a draft. With purchase_request_id, the expense is linked to that
     * approved purchase request (same project and line item), which becomes
     * fulfilled: one audit entry for the expense and one for the request.
     */
    public function create(Project $project, array $data, User $actor): Expense
    {
        $this->requireRole($project, $actor, array_merge(self::ENCODERS, [self::LEADER]), 'Only a Research Assistant, Finance/Admin staff or the Project Leader of this project can record expenses.');
        $linkId = $data['purchase_request_id'] ?? null;

        [$expense, $request] = AuditLogger::transaction(function () use ($project, $data, $actor, $linkId) {
            $expense = Expense::create(array_merge($this->only($data), [
                'project_id' => $project->id,
                'purchase_request_id' => $linkId,
                'recorded_by' => $actor->id,
                'workflow_status' => 'draft',
                'status' => Expense::STATUS_FOR_WORKFLOW['draft'],
            ]));

            AuditLogger::write('created', $expense, [], $this->snapshot($expense), $actor);
            $request = $linkId ? $this->purchaseRequests->fulfil(PurchaseRequest::findOrFail($linkId), $expense, $actor) : null;

            return [$expense->fresh(), $request];
        });

        if ($request) {
            $this->purchaseRequests->notifyFulfilled($request, $expense, $actor);
        }

        return $expense;
    }

    public function update(Expense $expense, array $data, User $actor): Expense
    {
        $this->requireEncoder($expense, $actor, 'edit');
        $this->requireState($expense, ['draft', 'returned'], 'edited');

        [$updated, $request] = AuditLogger::transaction(function () use ($expense, $data, $actor) {
            $locked = $this->lock($expense);
            $this->requireState($locked, ['draft', 'returned'], 'edited');
            $linkId = $data['purchase_request_id'] ?? null;
            $current = $locked->purchase_request_id;

            if ($linkId && $current && (int) $linkId !== (int) $current) {
                throw ValidationException::withMessages(['purchase_request_id' => ['This expense is already linked to a purchase request.']]);
            }
            $newLink = $linkId && ! $current ? (int) $linkId : null;
            $lineItem = (int) ($data['lib_category_id'] ?? $locked->lib_category_id);
            if ($current && $lineItem !== (int) $locked->lib_category_id) {
                throw ValidationException::withMessages(['lib_category_id' => ["A linked expense stays on its purchase request's line item."]]);
            }

            $old = $this->snapshot($locked);
            $locked->update($this->only($data) + ($newLink ? ['purchase_request_id' => $newLink] : []));
            AuditLogger::write('updated', $locked, $old, $this->snapshot($locked->fresh()), $actor);
            $request = $newLink ? $this->purchaseRequests->fulfil(PurchaseRequest::findOrFail($newLink), $locked->fresh(), $actor) : null;

            return [$locked->fresh(), $request];
        });

        if ($request) {
            $this->purchaseRequests->notifyFulfilled($request, $updated, $actor);
        }

        return $updated;
    }

    public function delete(Expense $expense, User $actor): void
    {
        $this->requireEncoder($expense, $actor, 'delete');
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

            // A linked purchase request is open (approved, unfulfilled) again.
            if ($locked->purchase_request_id && $request = PurchaseRequest::find($locked->purchase_request_id)) {
                $this->purchaseRequests->release($request, $locked, $actor);
            }
        });
    }

    // ---------------------------------------------------------------- hand-offs

    public function submit(Expense $expense, User $actor): Expense
    {
        $byLeader = $this->requireEncoder($expense, $actor, 'submit');
        $this->requireState($expense, ['draft', 'returned'], 'submitted');

        if (! $expense->receipts()->exists()) {
            throw ValidationException::withMessages([
                'documents' => ['Attach at least one receipt before submitting.'],
            ]);
        }

        // A Project Leader's own expense skips their approval step.
        $expense = $this->move($expense, $byLeader ? 'endorsed' : 'submitted', $actor, 'submitted', ['draft', 'returned'], [
            'submitted_at' => now(),
            'return_reason' => null,
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ]);

        if ($byLeader) {
            $this->notify($this->projectMembers($expense->project_id, 'finance-admin'), $expense, 'leader_submitted', $actor);
        } else {
            $this->notify($this->projectMembers($expense->project_id, 'project-leader'), $expense, 'submitted', $actor);
        }

        return $expense;
    }

    /**
     * Research Assistant / Finance/Admin of the project, or the Project
     * Leader for an expense they recorded. Returns true for the Project
     * Leader path.
     */
    private function requireEncoder(Expense $expense, User $actor, string $verb): bool
    {
        $role = $expense->project ? $actor->roleOn($expense->project) : null;
        if ($role === self::LEADER) {
            if ((int) $expense->recorded_by !== (int) $actor->id) {
                abort(403, "A Project Leader can only {$verb} expenses they recorded.");
            }

            return true;
        }
        $this->requireRole($expense->project, $actor, self::ENCODERS, "Only a Research Assistant or Finance/Admin staff of this project can {$verb} expenses.");

        return false;
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

    /**
     * A receipt was added to a verified expense that had none (the upload
     * itself wrote the one audit entry): tell the project's Finance/Admin.
     */
    public function notifyLateReceipt(Expense $expense, User $actor): void
    {
        $this->notify($this->projectMembers($expense->project_id, 'finance-admin'), $expense, 'late_receipt', $actor);
    }

    // ---------------------------------------------------------------- helpers

    protected function stageLabels(): array
    {
        return self::LABELS;
    }

    protected function noun(): string
    {
        return 'expense';
    }

    /** Change the stage (and keep `status` in sync) with exactly one audit entry. */
    private function move(Expense $expense, string $to, User $actor, string $auditAction, array $from, array $extra, ?string $reason = null): Expense
    {
        $verb = $auditAction === 'endorsed' ? 'approved' : $auditAction;

        return $this->moveRecord($expense, $to, $actor, $auditAction, $from, $verb, $extra, $reason, Expense::STATUS_FOR_WORKFLOW);
    }

    private function lock(Expense $expense): Expense
    {
        return $this->lockRecord($expense);
    }

    private function requireNotMaker(Expense $expense, User $actor, string $message): void
    {
        $this->requireNotSame($expense->recorded_by, $actor, $message);
    }

    private function requireNotEndorser(Expense $expense, User $actor): void
    {
        $this->requireNotSame($expense->pl_reviewed_by, $actor, 'You approved this expense as Project Leader; another person must verify it.');
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
            'project_id', 'lib_category_id', 'purchase_request_id', 'reference_no', 'payee', 'description', 'amount', 'expense_date', 'workflow_status',
        ]);
    }

    private function recorder(Expense $expense): Collection
    {
        return $this->activeUser($expense->recorded_by);
    }

    private function notify(Collection $recipients, Expense $expense, string $event, User $actor, ?string $reason = null): void
    {
        $this->notifyUsers($recipients, new ExpenseWorkflowNotification($expense->loadMissing(['project', 'libCategory']), $event, $actor, $reason), $actor);
    }
}

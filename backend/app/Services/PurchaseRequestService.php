<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\Concerns\RunsWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Purchase request workflow: draft -> submitted -> endorsed ("Approved by
 * Project Leader") or returned (back to draft-like editing). No Finance
 * step. A submit needs a quotation or invoice document.
 *
 * An approved request then ends as:
 *   fulfilled  an expense of the same project and line item is linked to it
 *              (ExpenseWorkflowService calls fulfil()); deleting that draft
 *              expense reopens the request (release())
 *   cancelled  by the Project Leader or the requester, with a reason
 *
 * One audit entry per step. The Project Leader is notified on submit,
 * fulfil and cancel; the requester on a decision (and on a cancel by
 * someone else).
 */
class PurchaseRequestService
{
    use RunsWorkflow;

    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'endorsed' => 'Approved by Project Leader',
        'returned' => 'Returned',
        'fulfilled' => 'Fulfilled',
        'cancelled' => 'Cancelled',
    ];

    private const ENCODERS = ['research-assistant', 'finance-admin'];
    private const EDITABLE = ['draft', 'returned'];
    private const FIELDS = ['lib_category_id', 'description', 'quantity', 'estimated_amount'];

    public function create(Project $project, array $data, User $actor): PurchaseRequest
    {
        $this->requireRole($project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can create purchase requests.');

        return AuditLogger::transaction(function () use ($project, $data, $actor) {
            $request = PurchaseRequest::create(array_merge(array_intersect_key($data, array_flip(self::FIELDS)), [
                'project_id' => $project->id,
                'requested_by' => $actor->id,
                'workflow_status' => 'draft',
            ]));
            AuditLogger::write('created', $request, [], $this->snapshot($request), $actor);

            return $request->fresh();
        });
    }

    public function update(PurchaseRequest $request, array $data, User $actor): PurchaseRequest
    {
        $this->requireRole($request->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can edit purchase requests.');
        $this->requireState($request, self::EDITABLE, 'edited');

        return AuditLogger::transaction(function () use ($request, $data, $actor) {
            $locked = $this->lockRecord($request);
            $this->requireState($locked, self::EDITABLE, 'edited');
            $old = $this->snapshot($locked);
            $locked->update(array_intersect_key($data, array_flip(self::FIELDS)));
            AuditLogger::write('updated', $locked, $old, $this->snapshot($locked->fresh()), $actor);

            return $locked->fresh();
        });
    }

    public function delete(PurchaseRequest $request, User $actor): void
    {
        $this->requireRole($request->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can delete purchase requests.');
        $this->requireRequester($request, $actor);
        $this->requireState($request, ['draft'], 'deleted');

        AuditLogger::transaction(function () use ($request, $actor) {
            $locked = $this->lockRecord($request);
            $this->requireState($locked, ['draft'], 'deleted');
            $old = $this->snapshot($locked);
            $locked->delete(); // soft delete
            AuditLogger::write('deleted', $locked, $old, [], $actor);
        });
    }

    public function submit(PurchaseRequest $request, User $actor): PurchaseRequest
    {
        $this->requireRole($request->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can submit purchase requests.');
        $this->requireState($request, self::EDITABLE, 'submitted');

        if (! $request->supportingDocuments()->exists()) {
            throw ValidationException::withMessages([
                'documents' => ['Attach a quotation or invoice before submitting.'],
            ]);
        }

        $request = $this->moveRecord($request, 'submitted', $actor, 'submitted', self::EDITABLE, 'submitted', [
            'submitted_at' => now(),
            'return_reason' => null,
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ]);

        $this->notify($this->projectMembers($request->project_id, 'project-leader'), $request, 'submitted', $actor);

        return $request;
    }

    public function endorse(PurchaseRequest $request, User $actor): PurchaseRequest
    {
        $this->checkLeader($request, $actor, 'approve');
        $this->requireState($request, ['submitted'], 'approved');

        $request = $this->moveRecord($request, 'endorsed', $actor, 'endorsed', ['submitted'], 'approved', [
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ]);

        $this->notify($this->activeUser($request->requested_by), $request, 'endorsed', $actor);

        return $request;
    }

    public function returnToRequester(PurchaseRequest $request, string $reason, User $actor): PurchaseRequest
    {
        $this->checkLeader($request, $actor, 'return');
        $this->requireState($request, ['submitted'], 'returned');
        $reason = $this->requireReason($reason, 'A reason is required to return a purchase request.');

        $request = $this->moveRecord($request, 'returned', $actor, 'returned', ['submitted'], 'returned', [
            'return_reason' => $reason,
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ], $reason);

        $this->notify($this->activeUser($request->requested_by), $request, 'returned', $actor, $reason);

        return $request;
    }

    // ---------------------------------------------------------------- after approval

    /**
     * Link an expense to an approved request and mark the request fulfilled,
     * with one audit entry. Runs inside the caller's transaction; the
     * caller sends notifyFulfilled() after the commit.
     */
    public function fulfil(PurchaseRequest $request, Expense $expense, User $actor): PurchaseRequest
    {
        $locked = $this->lockRecord($request);

        if ((int) $locked->project_id !== (int) $expense->project_id || (int) $locked->lib_category_id !== (int) $expense->lib_category_id) {
            throw ValidationException::withMessages([
                'purchase_request_id' => ['Link an approved purchase request of the same project and line item.'],
            ]);
        }
        if ($locked->workflow_status !== PurchaseRequest::OPEN_APPROVED) {
            $label = strtolower(self::LABELS[$locked->workflow_status] ?? $locked->workflow_status);
            throw ValidationException::withMessages([
                'purchase_request_id' => ["Only an approved purchase request can be linked; this one is {$label}."],
            ]);
        }
        if (Expense::query()->where('purchase_request_id', $locked->id)->whereKeyNot($expense->id)->exists()) {
            throw ValidationException::withMessages([
                'purchase_request_id' => ['This purchase request is already linked to another expense.'],
            ]);
        }

        $old = $locked->only(['workflow_status', 'fulfilled_at', 'fulfilled_by']);
        $locked->update(['workflow_status' => 'fulfilled', 'fulfilled_at' => now(), 'fulfilled_by' => $actor->id]);
        AuditLogger::write('fulfilled', $locked, $old + ['expense_id' => null], $locked->fresh()->only(array_keys($old)) + ['expense_id' => $expense->id], $actor);

        return $locked->fresh();
    }

    public function notifyFulfilled(PurchaseRequest $request, Expense $expense, User $actor): void
    {
        $this->notify($this->projectMembers($request->project_id, 'project-leader'), $request, 'fulfilled', $actor, null, $expense);
    }

    /**
     * The linked draft / returned expense was deleted: the request is open
     * again (approved, unfulfilled), with one audit entry. Runs inside the
     * caller's transaction.
     */
    public function release(PurchaseRequest $request, Expense $expense, User $actor): void
    {
        $locked = $this->lockRecord($request);
        if ($locked->workflow_status !== 'fulfilled') {
            return;
        }

        $old = $locked->only(['workflow_status', 'fulfilled_at', 'fulfilled_by']);
        $locked->update(['workflow_status' => PurchaseRequest::OPEN_APPROVED, 'fulfilled_at' => null, 'fulfilled_by' => null]);
        AuditLogger::write('reopened', $locked, $old + ['expense_id' => $expense->id], $locked->fresh()->only(array_keys($old)) + ['expense_id' => null], $actor, 'The linked expense was deleted.');
    }

    /** Cancel an approved, unfulfilled request (Project Leader or requester; reason required). */
    public function cancel(PurchaseRequest $request, string $reason, User $actor): PurchaseRequest
    {
        $isLeader = $request->project && $actor->roleOn($request->project) === 'project-leader';
        $isRequester = (int) $request->requested_by === (int) $actor->id;
        if (! $isLeader && ! $isRequester) {
            abort(403, 'Only the Project Leader or the person who made this request can cancel it.');
        }
        $this->requireState($request, [PurchaseRequest::OPEN_APPROVED], 'cancelled');
        $reason = $this->requireReason($reason, 'A reason is required to cancel a purchase request.');

        $request = $this->moveRecord($request, 'cancelled', $actor, 'cancelled', [PurchaseRequest::OPEN_APPROVED], 'cancelled', [
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ], $reason);

        $recipients = $this->projectMembers($request->project_id, 'project-leader')
            ->merge($this->activeUser($request->requested_by))
            ->unique('id')
            ->values();
        $this->notify($recipients, $request, 'cancelled', $actor, $reason);

        return $request;
    }

    protected function stageLabels(): array
    {
        return self::LABELS;
    }

    protected function noun(): string
    {
        return 'purchase request';
    }

    private function checkLeader(PurchaseRequest $request, User $actor, string $verb): void
    {
        $this->requireRole($request->project, $actor, ['project-leader'], "Only the Project Leader of this project can {$verb} purchase requests.");
        $this->requireNotSame($request->requested_by, $actor, 'You cannot approve or return a purchase request you made.');
    }

    private function requireRequester(PurchaseRequest $request, User $actor): void
    {
        if ((int) $request->requested_by !== (int) $actor->id) {
            abort(403, 'You can only delete purchase requests you made.');
        }
    }

    private function snapshot(PurchaseRequest $request): array
    {
        return $request->only(['project_id', 'lib_category_id', 'description', 'quantity', 'estimated_amount', 'workflow_status']);
    }

    private function notify(Collection $recipients, PurchaseRequest $request, string $event, User $actor, ?string $reason = null, ?Expense $expense = null): void
    {
        $request->loadMissing('project');
        $what = $request->description . ' (' . $request->quantity . ' × ₱' . number_format((float) $request->estimated_amount, 2) . ' est.)';
        $because = $reason ? ' Reason: ' . $reason : '';

        [$title, $message] = match ($event) {
            'submitted' => ['Purchase request waiting for your approval', "{$actor->name} submitted a purchase request: {$what}."],
            'endorsed' => ['Purchase request approved', "{$actor->name} (Project Leader) approved your purchase request: {$what}."],
            'returned' => ['Purchase request returned to you', "{$actor->name} returned your purchase request: {$request->description}.{$because}"],
            'fulfilled' => ['Purchase request fulfilled', "{$actor->name} recorded an expense (₱" . number_format((float) $expense?->amount, 2) . ") that fulfils the purchase request: {$request->description}."],
            'cancelled' => ['Purchase request cancelled', "{$actor->name} cancelled the approved purchase request: {$request->description}.{$because}"],
        };

        $this->notifyUsers($recipients, new WorkflowNotification([
            'kind' => 'purchase_request',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'purchase_request_id' => $request->id,
            'project_id' => $request->project_id,
            'project' => $request->project?->title,
            'actor' => $actor->name,
            'reason' => $reason,
            'link' => '/purchase-requests?project=' . $request->project_id . '&request=' . $request->id,
        ]), $actor);
    }
}

<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Project;
use App\Models\LibCategory;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
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
 * A request has one or more items (MSU Purchase Request form), each charged
 * to its own line item: MOOE or a PS line without personnel (honoraria lines
 * are paid through Payroll only). Estimated cost = quantity x unit cost,
 * computed here; the request total is the sum. On submit and on approval
 * the items charged to each line item may not exceed its available amount
 * (the same rule as expenses, counting pending). An approved request holds
 * its items' amounts as pending on each line item.
 *
 * Finance/Admin fills in the PR, SAI and ALOBS numbers and dates of an
 * approved (or fulfilled) request with setNumbers().
 *
 * An approved request then ends as:
 *   fulfilled  for every line item of its items an expense of that line item
 *              is linked to it (ExpenseWorkflowService calls fulfil(), one
 *              expense per line item); deleting a linked draft expense
 *              reopens that line item (release())
 *   cancelled  by the Project Leader or the requester, with a reason
 *
 * One audit entry per step. The Project Leader is notified on submit,
 * fulfil and cancel; the requester on a decision (and on a cancel by
 * someone else).
 *
 * A project has one Project Leader and the maker never approves: a request
 * made by the project's Project Leader (older data where they also hold an
 * encoder role, or they became the Project Leader later) skips the Project
 * Leader step. It is open for an expense at once (endorsed with
 * pl_reviewed_by empty, "Approved without Project Leader step") and
 * Finance/Admin is told; Finance/Admin then verifies the linked expense.
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

    /** Label of an endorsed request that skipped the Project Leader step (made by the Project Leader). */
    public const NO_LEADER_STEP = 'Approved without Project Leader step (made by the Project Leader)';

    private const ENCODERS = ['research-assistant', 'finance-admin'];

    /** The stage label of one request. */
    public static function label(PurchaseRequest $request): string
    {
        if ($request->workflow_status === 'endorsed' && $request->pl_reviewed_by === null) {
            return self::NO_LEADER_STEP;
        }

        return self::LABELS[$request->workflow_status] ?? (string) $request->workflow_status;
    }
    private const EDITABLE = ['draft', 'returned'];

    /**
     * Funder by default; the counterpart only on a line item that has one.
     * $fallback: a kept source that no longer fits the line item goes back
     * to the funder instead of failing.
     */
    private function fundingSource($lineItemId, ?string $requested, bool $fallback = false): string
    {
        $item = $lineItemId ? \App\Models\LibCategory::find($lineItemId) : null;
        if (! $item) {
            return \App\Models\LibCategory::SOURCE_FUNDER;
        }
        if ($fallback && ! $item->hasCounterpart()) {
            return \App\Models\LibCategory::SOURCE_FUNDER;
        }

        return $item->resolveFundingSource($requested);
    }
    /**
     * $data: description (purpose), supplier, funding_source and items
     * (each: lib_category_id, quantity, unit, description, unit_cost).
     */
    public function create(Project $project, array $data, User $actor): PurchaseRequest
    {
        $this->requireRole($project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can create purchase requests.');
        $items = array_values($data['items'] ?? []);
        $this->requireNotHonoraria($project->id, $items);

        return AuditLogger::transaction(function () use ($project, $data, $actor, $items) {
            $requested = $data['funding_source'] ?? null;
            $request = PurchaseRequest::create(array_merge(array_intersect_key($data, array_flip(['description', 'supplier'])), [
                'project_id' => $project->id,
                'requested_by' => $actor->id,
                'workflow_status' => 'draft',
                'funding_source' => $this->requestSource($items, $requested),
            ], $this->summary($items)));
            $this->writeItems($request, $items, $requested);
            AuditLogger::write('created', $request, [], $this->snapshot($request->fresh()), $actor);

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
            $requested = array_key_exists('funding_source', $data) ? $data['funding_source'] : $locked->funding_source;
            // A kept source that no longer fits goes back to the funder; one asked for must fit.
            $fallback = ! array_key_exists('funding_source', $data);

            $items = array_key_exists('items', $data)
                ? array_values($data['items'])
                : (array_key_exists('funding_source', $data) ? $this->itemsAsInput($locked) : null);
            $changes = array_intersect_key($data, array_flip(['description', 'supplier']));
            if ($items !== null) {
                $this->requireNotHonoraria((int) $locked->project_id, $items);
                $changes = array_merge($changes, $this->summary($items), [
                    'funding_source' => $this->requestSource($items, $requested, $fallback),
                ]);
            }
            $locked->update($changes);
            if ($items !== null) {
                $this->writeItems($locked, $items, $requested);
            }
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

        $this->requireNotHonoraria((int) $request->project_id, $this->itemsAsInput($request));
        $this->requireAvailable($request);

        // Made by the project's Project Leader: no Project Leader step.
        $byLeader = $this->makerLeadsProject($request->project_id, $request->requested_by);

        $request = $this->moveRecord($request, $byLeader ? 'endorsed' : 'submitted', $actor, 'submitted', self::EDITABLE, 'submitted', [
            'submitted_at' => now(),
            'return_reason' => null,
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ]);

        if ($byLeader) {
            $this->notify($this->projectMembers($request->project_id, 'finance-admin'), $request, 'leader_submitted', $actor);
        } else {
            $this->notify($this->projectMembers($request->project_id, 'project-leader'), $request, 'submitted', $actor);
        }

        return $request;
    }

    /**
     * A submitted request made by the project's Project Leader has nobody to
     * approve it: open it for an expense without the Project Leader step
     * (one audit entry, "rerouted"). Returns false when it does not apply.
     */
    public function skipLeaderStep(PurchaseRequest $request, User $actor): bool
    {
        if ($request->workflow_status !== 'submitted' || ! $this->makerLeadsProject($request->project_id, $request->requested_by)) {
            return false;
        }

        $this->moveRecord($request, 'endorsed', $actor, 'rerouted', ['submitted'], 'rerouted', [
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ], 'Made by the Project Leader, so it skips the Project Leader approval.');
        $this->notify($this->projectMembers($request->project_id, 'finance-admin'), $request->fresh(), 'leader_submitted', $actor);

        return true;
    }

    public function endorse(PurchaseRequest $request, User $actor): PurchaseRequest
    {
        $this->checkLeader($request, $actor, 'approve');
        $this->requireState($request, ['submitted'], 'approved');
        // The balance may have changed since the submit.
        $this->requireAvailable($request);

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
        $lines = $locked->lineItemIds();

        if ((int) $locked->project_id !== (int) $expense->project_id || ! in_array((int) $expense->lib_category_id, $lines, true)) {
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
        $others = Expense::query()->where('purchase_request_id', $locked->id)->whereKeyNot($expense->id);
        if ((clone $others)->where('lib_category_id', $expense->lib_category_id)->exists()) {
            throw ValidationException::withMessages([
                'purchase_request_id' => [count($lines) > 1
                    ? "This purchase request's items on this line item are already linked to another expense."
                    : 'This purchase request is already linked to another expense.'],
            ]);
        }

        // Fulfilled once every line item of its items has a linked expense.
        $covered = (clone $others)->pluck('lib_category_id')->map(fn ($id) => (int) $id)->push((int) $expense->lib_category_id)->unique()->all();
        if (array_diff($lines, $covered) !== []) {
            AuditLogger::write('linked', $locked, ['expense_id' => null], ['expense_id' => $expense->id, 'lib_category_id' => (int) $expense->lib_category_id], $actor, 'An expense was linked for one line item; the other line items are still open.');

            return $locked->fresh();
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
        if ($locked->workflow_status === PurchaseRequest::OPEN_APPROVED) {
            // Partly linked: that line item is open again.
            AuditLogger::write('unlinked', $locked, ['expense_id' => $expense->id], ['expense_id' => null], $actor, 'The linked expense was deleted.');

            return;
        }
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
        $isLeader = $request->project && $actor->hasRoleOn($request->project, 'project-leader');
        $isRequester = (int) $request->requested_by === (int) $actor->id;
        if (! $isLeader && ! $isRequester) {
            abort(403, 'Only the Project Leader or the person who made this request can cancel it.');
        }
        $this->requireState($request, [PurchaseRequest::OPEN_APPROVED], 'cancelled');
        $reason = $this->requireReason($reason, 'A reason is required to cancel a purchase request.');
        if ($request->expenses()->exists()) {
            throw ValidationException::withMessages([
                'workflow_status' => ['An expense is already linked to this purchase request, so it cannot be cancelled.'],
            ]);
        }

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

    /**
     * Finance/Admin fills in the PR No., SAI No. and ALOBS No. (and dates) of
     * an approved or fulfilled request. One audit entry.
     */
    public function setNumbers(PurchaseRequest $request, array $data, User $actor): PurchaseRequest
    {
        $this->requireRole($request->project, $actor, ['finance-admin'], 'Only Finance/Admin staff of this project can set the PR, SAI and ALOBS numbers.');
        $allowed = [PurchaseRequest::OPEN_APPROVED, 'fulfilled'];
        $this->requireState($request, $allowed, 'given PR, SAI or ALOBS numbers');

        return AuditLogger::transaction(function () use ($request, $data, $actor, $allowed) {
            $locked = $this->lockRecord($request);
            $this->requireState($locked, $allowed, 'given PR, SAI or ALOBS numbers');
            $old = $this->numbers($locked);
            $locked->update(array_intersect_key($data, array_flip(PurchaseRequest::NUMBER_FIELDS)));
            AuditLogger::write('updated', $locked, $old, $this->numbers($locked->fresh()), $actor);

            return $locked->fresh();
        });
    }

    // ---------------------------------------------------------------- items

    /** Quantity x unit cost, computed in centavos so it never drifts. */
    public static function cost(int $quantity, $unitCost): float
    {
        return round(((int) round((float) $unitCost * 100)) * $quantity / 100, 2);
    }

    /** The request's summary columns from its items. */
    private function summary(array $items): array
    {
        $cents = 0;
        $quantity = 0;
        foreach ($items as $item) {
            $cents += (int) round($this->itemCost($item) * 100);
            $quantity += (int) $item['quantity'];
        }

        return [
            'estimated_amount' => round($cents / 100, 2),
            'quantity' => max(1, $quantity),
            'lib_category_id' => (int) ($items[0]['lib_category_id'] ?? 0),
        ];
    }

    /** Computed, except a migrated item keeps its stored cost exactly. */
    private function itemCost(array $item): float
    {
        return ! empty($item['keep_cost'])
            ? round((float) $item['estimated_cost'], 2)
            : self::cost((int) $item['quantity'], $item['unit_cost']);
    }

    /**
     * The request-level source as asked: the counterpart only when an item's
     * line item has one. With $fallback (a kept choice) a source that no
     * longer fits goes back to the funder instead of failing.
     */
    private function requestSource(array $items, ?string $requested, bool $fallback = false): string
    {
        if ($requested === null || $requested === '' || $requested === LibCategory::SOURCE_FUNDER) {
            return LibCategory::SOURCE_FUNDER;
        }
        foreach ($items as $item) {
            if ($this->fundingSource($item['lib_category_id'] ?? null, $requested, true) === $requested) {
                return $requested;
            }
        }
        if ($fallback) {
            return LibCategory::SOURCE_FUNDER;
        }

        // Fails with the line item's own message ("no counterpart").
        return $this->fundingSource($items[0]['lib_category_id'] ?? null, $requested);
    }

    /** Replace the request's items; each draws on the asked source when its line item has it. */
    private function writeItems(PurchaseRequest $request, array $items, ?string $requested): void
    {
        $request->items()->delete();
        foreach (array_values($items) as $index => $item) {
            PurchaseRequestItem::create([
                'purchase_request_id' => $request->id,
                'lib_category_id' => (int) $item['lib_category_id'],
                'funding_source' => $this->fundingSource($item['lib_category_id'], $requested, true),
                'quantity' => (int) $item['quantity'],
                'unit' => $item['unit'],
                'description' => trim((string) $item['description']),
                'unit_cost' => round((float) $item['unit_cost'], 2),
                'estimated_cost' => $this->itemCost($item),
                'sort_order' => $index,
            ]);
        }
    }

    /** The stored items as input rows (migrated estimated costs are kept exactly). */
    private function itemsAsInput(PurchaseRequest $request): array
    {
        return $request->lineItems()->map(fn (PurchaseRequestItem $item) => [
            'lib_category_id' => (int) $item->lib_category_id,
            'quantity' => (int) $item->quantity,
            'unit' => $item->unit,
            'description' => $item->description,
            'unit_cost' => (float) $item->unit_cost,
            'estimated_cost' => (float) $item->estimated_cost,
            'keep_cost' => true,
        ])->values()->all();
    }

    /** Honoraria lines (personnel linked) are paid through Payroll only. */
    private function requireNotHonoraria(int $projectId, array $items): void
    {
        $honoraria = ProjectFinancialService::honorariaLineIds($projectId);
        foreach (array_values($items) as $index => $item) {
            if (in_array((int) ($item['lib_category_id'] ?? 0), $honoraria, true)) {
                throw ValidationException::withMessages(["items.{$index}.lib_category_id" => [
                    ProjectFinancialService::HONORARIA_LINE . ' Choose another line item for this item.',
                ]]);
            }
        }
    }

    /**
     * Per line item and source, the items charged to it may not exceed the
     * available amount (allocated - spent - pending). A submitted request is
     * not pending yet, so it is not counted against itself.
     */
    private function requireAvailable(PurchaseRequest $request): void
    {
        $finance = app(ProjectFinancialService::class);
        $project = $request->project;
        $problems = [];
        foreach ($request->amountsByLine() as $row) {
            $balance = $finance->sourceBalance($project, $row['lib_category_id'], $row['funding_source']);
            if (! $balance) {
                continue;
            }
            $available = round((float) $balance['available'], 2);
            if ($row['amount'] > $available) {
                $problems[] = sprintf(
                    'The items charged to %s total ₱%s, more than the ₱%s available.',
                    $balance['name'],
                    number_format($row['amount'], 2),
                    number_format(max(0, $available), 2)
                );
            }
        }
        if ($problems) {
            $problems[] = 'Lower the amounts or ask the Project Leader for a realignment.';
            throw ValidationException::withMessages(['items' => [implode(' ', $problems)]]);
        }
    }

    private function numbers(PurchaseRequest $request): array
    {
        return collect(PurchaseRequest::NUMBER_FIELDS)
            ->mapWithKeys(fn ($field) => [$field => $request->{$field} instanceof \DateTimeInterface ? $request->{$field}->format('Y-m-d') : $request->{$field}])
            ->all();
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
        return $request->only(['project_id', 'lib_category_id', 'funding_source', 'description', 'quantity', 'estimated_amount', 'supplier', 'workflow_status'])
            + ['items' => $request->lineItems()->map(fn (PurchaseRequestItem $item) => sprintf(
                '%d %s %s @ %s = %s',
                $item->quantity, $item->unit, $item->description,
                number_format((float) $item->unit_cost, 2), number_format((float) $item->estimated_cost, 2)
            ))->implode('; ')];
    }

    private function notify(Collection $recipients, PurchaseRequest $request, string $event, User $actor, ?string $reason = null, ?Expense $expense = null): void
    {
        $request->loadMissing('project');
        $count = $request->lineItems()->count();
        $what = $request->description . ' (' . $count . ' ' . ($count === 1 ? 'item' : 'items') . ', ₱' . number_format((float) $request->estimated_amount, 2) . ' est.)';
        $because = $reason ? ' Reason: ' . $reason : '';

        [$title, $message] = match ($event) {
            'submitted' => ['Purchase request waiting for your approval', "{$actor->name} submitted a purchase request: {$what}."],
            'endorsed' => ['Purchase request approved', "{$actor->name} (Project Leader) approved your purchase request: {$what}."],
            'leader_submitted' => ['Purchase request by the Project Leader', "{$request->requestedBy?->name} (Project Leader) made a purchase request: {$what}. It skips the Project Leader approval; you verify the expense recorded for it."],
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

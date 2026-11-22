<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\LibCategory;
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
 * delete, attach receipts to and submit only the ones they recorded). A
 * project has one Project Leader and the maker never approves, so an
 * expense RECORDED by the project's Project Leader (however it is
 * submitted) skips the Project Leader step: draft -> endorsed ("Waiting for
 * Finance/Admin verification", pl_reviewed_by stays empty), with the same
 * receipt rule, one audit entry ("submitted") and a notification to
 * Finance/Admin. One already waiting on its own maker (older data, or the
 * recorder became the Project Leader later) is moved on by
 * skipLeaderStep(). The recorder never verifies.
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

    /**
     * Who records expenses: the Research Assistant (and the Project Leader,
     * on their own project). Finance/Admin verifies and does not encode; an
     * older expense a Finance/Admin recorded can still be edited, resubmitted
     * or deleted by that same person (requireEncoder()).
     */
    private const ENCODER = 'research-assistant';
    private const FINANCE = 'finance-admin';
    private const LEADER = 'project-leader';

    /** Label of an approved expense the Program Leader verifies (no Finance/Admin may). */
    public const WAITING_PROGRAM_LEADER = 'Waiting for the Program Leader to verify (no other Finance/Admin can)';

    /** The stage label of one expense. */
    public static function label(Expense $expense): string
    {
        if ($expense->workflow_status === 'endorsed' && $expense->pendingEscalation() !== null) {
            return self::WAITING_PROGRAM_LEADER;
        }
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
     * Record a draft. With purchase_request_id (and purchase_request_item_ids,
     * the ticked items, all on the expense's line item), the expense pays
     * those items of the approved request; the request is fulfilled once no
     * item is open. One audit entry for the expense and one for the request.
     */
    public function create(Project $project, array $data, User $actor): Expense
    {
        // Finance/Admin verifies against the documents; it does not encode.
        $this->requireRole($project, $actor, [self::ENCODER, self::LEADER], 'Only a Research Assistant or the Project Leader of this project can record expenses. Finance/Admin verifies them.');
        $linkId = $data['purchase_request_id'] ?? null;
        $this->requireNotHonoraria($project->id, $data['lib_category_id'] ?? null);

        [$expense, $request] = AuditLogger::transaction(function () use ($project, $data, $actor, $linkId) {
            $data['funding_source'] = $this->fundingSource($data['lib_category_id'] ?? null, $data['funding_source'] ?? null, $linkId);
            $expense = Expense::create(array_merge($this->only($data), [
                'project_id' => $project->id,
                'purchase_request_id' => $linkId,
                'recorded_by' => $actor->id,
                'workflow_status' => 'draft',
                'status' => Expense::STATUS_FOR_WORKFLOW['draft'],
            ]));

            AuditLogger::write('created', $expense, [], $this->snapshot($expense), $actor);
            $request = $linkId ? $this->purchaseRequests->fulfil(PurchaseRequest::findOrFail($linkId), $expense, $actor, $data['purchase_request_item_ids'] ?? null) : null;

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
            $this->requireNotHonoraria((int) $locked->project_id, $lineItem);

            // Re-resolved when the line item, the source or the link changes:
            // a hidden "Funded by" choice (no counterpart) means the funder.
            if ($newLink || array_key_exists('lib_category_id', $data) || array_key_exists('funding_source', $data)) {
                $requested = array_key_exists('funding_source', $data) ? $data['funding_source'] : $locked->funding_source;
                $item = LibCategory::find($lineItem);
                $data['funding_source'] = $item && ! $item->hasCounterpart() && ! array_key_exists('funding_source', $data)
                    ? LibCategory::SOURCE_FUNDER
                    : $this->fundingSource($lineItem, $requested, $newLink ?: $current);
            }

            // Already linked: the amount stays at most the items it pays.
            $itemsTotal = $current && array_key_exists('amount', $data) ? PurchaseRequestService::linkedItemsTotal($locked) : 0.0;
            if ($itemsTotal > 0) {
                $this->purchaseRequests->requireWithinItems((float) $data['amount'], $itemsTotal);
            }

            $old = $this->snapshot($locked);
            $locked->update($this->only($data) + ($newLink ? ['purchase_request_id' => $newLink] : []));
            AuditLogger::write('updated', $locked, $old, $this->snapshot($locked->fresh()), $actor);
            $request = $newLink ? $this->purchaseRequests->fulfil(PurchaseRequest::findOrFail($newLink), $locked->fresh(), $actor, $data['purchase_request_item_ids'] ?? null) : null;

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
        $this->requireEncoder($expense, $actor, 'submit');
        $this->requireState($expense, ['draft', 'returned'], 'submitted');
        // Recorded by the project's Project Leader: no Project Leader step.
        $byLeader = $this->makerLeadsProject($expense->project_id, $expense->recorded_by);

        if (! $expense->receipts()->exists()) {
            throw ValidationException::withMessages([
                'documents' => ['Attach at least one receipt before submitting.'],
            ]);
        }
        $this->requireNotHonoraria((int) $expense->project_id, $expense->lib_category_id);
        $this->requireAvailable($expense);

        // A Project Leader's own expense skips their approval step.
        $expense = $this->move($expense, $byLeader ? 'endorsed' : 'submitted', $actor, 'submitted', ['draft', 'returned'], [
            'submitted_at' => now(),
            'return_reason' => null,
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ]);

        if ($byLeader) {
            $expense = $this->escalateVerifyIfNeeded($expense, $actor);
            $this->notifyVerifiers($expense, 'leader_submitted', $actor);
        } else {
            $this->notify($this->projectMembers($expense->project_id, 'project-leader'), $expense, 'submitted', $actor);
        }

        return $expense;
    }

    /**
     * No double counting: a PS line item that personnel are linked to
     * (honoraria line) is paid through Payroll only, never as an expense.
     */
    private function requireNotHonoraria(int $projectId, $lineItemId): void
    {
        if ($lineItemId && in_array((int) $lineItemId, ProjectFinancialService::honorariaLineIds($projectId), true)) {
            throw ValidationException::withMessages(['lib_category_id' => [
                ProjectFinancialService::HONORARIA_LINE . ' Choose another line item for this expense.',
            ]]);
        }
    }

    /**
     * The amount may not exceed the line item's available balance
     * (allocated - spent - pending, ProjectFinancialService::balancing). A
     * linked expense already counts in "pending" (it took over its purchase
     * request's money), so its own amount is added back.
     */
    private function requireAvailable(Expense $expense): void
    {
        $row = app(ProjectFinancialService::class)
            ->sourceBalance($expense->project, (int) $expense->lib_category_id, $expense->funding_source);
        if (! $row) {
            return;
        }
        $available = round((float) $row['available'] + ($expense->purchase_request_id ? (float) $expense->amount : 0), 2);
        if (round((float) $expense->amount, 2) > $available) {
            // Name the share only when the item has two sources.
            $labels = ProjectFinancialService::sourceLabels($expense->project);
            $share = ! $row['has_counterpart'] ? '' : ' (' . ($row['source'] === LibCategory::SOURCE_COUNTERPART
                ? $labels['implementing_agency'] . ' counterpart'
                : $labels['funder'] . ' share') . ')';
            throw ValidationException::withMessages(['amount' => [sprintf(
                'The amount (₱%s) is more than the ₱%s available on %s%s. Lower the amount or ask the Project Leader for a realignment.',
                number_format((float) $expense->amount, 2),
                number_format(max(0, $available), 2),
                $row['name'],
                $share
            )]]);
        }
    }

    /**
     * The source an expense draws from. A linked purchase request decides it
     * (the expense takes over that request's money); otherwise the funder
     * unless the counterpart is asked for on a line item that has one.
     */
    private function fundingSource($lineItemId, ?string $requested, $purchaseRequestId = null): string
    {
        if ($purchaseRequestId && $request = PurchaseRequest::find($purchaseRequestId)) {
            // The source of the request's items on this line item.
            $item = $request->lineItems()->first(fn ($row) => (int) $row->lib_category_id === (int) $lineItemId);

            return ProjectFinancialService::source($item?->funding_source ?? $request->funding_source);
        }
        $item = $lineItemId ? LibCategory::find($lineItemId) : null;

        return $item ? $item->resolveFundingSource($requested) : LibCategory::SOURCE_FUNDER;
    }

    /**
     * A Research Assistant of the project; or the Project Leader, or a
     * Finance/Admin (older data), for an expense they recorded. Returns true
     * for the Project Leader path.
     */
    private function requireEncoder(Expense $expense, User $actor, string $verb): bool
    {
        $project = $expense->project;
        if ($project && $actor->hasRoleOn($project, self::ENCODER)) {
            return false;
        }
        $leader = $project && $actor->hasRoleOn($project, self::LEADER);
        if ($leader || ($project && $actor->hasRoleOn($project, self::FINANCE))) {
            if ((int) $expense->recorded_by !== (int) $actor->id) {
                abort(403, $leader
                    ? "A Project Leader can only {$verb} expenses they recorded."
                    : "Finance/Admin can only {$verb} an expense they recorded. Finance/Admin verifies expenses; the Research Assistant records them.");
            }

            return $leader;
        }
        abort(403, "Only a Research Assistant or the Project Leader of this project can {$verb} expenses.");
    }

    /**
     * A submitted expense recorded by the project's Project Leader has nobody
     * to approve it: move it on to Finance/Admin (one audit entry,
     * "rerouted"). Returns false when it does not apply.
     */
    public function skipLeaderStep(Expense $expense, User $actor): bool
    {
        if ($expense->workflow_status !== 'submitted' || ! $this->makerLeadsProject($expense->project_id, $expense->recorded_by)) {
            return false;
        }

        $this->move($expense, 'endorsed', $actor, 'rerouted', ['submitted'], [
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ], 'Recorded by the Project Leader, so it goes to Finance/Admin without a Project Leader approval.');
        $this->notifyVerifiers($this->escalateVerifyIfNeeded($expense->fresh(), $actor), 'leader_submitted', $actor);

        return true;
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
        $expense = $this->escalateVerifyIfNeeded($expense, $actor);
        $this->notifyVerifiers($expense, 'endorsed', $actor);

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
        $this->requireNotEscalated($expense);
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
        $this->requireNotEscalated($expense);
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

    // ---------------------------------------------------------------- Program Leader escalation

    /**
     * Finance/Admin of the project who may verify this expense: not its
     * recorder, not the person who approved it.
     */
    public function eligibleVerifiers(Expense $expense): Collection
    {
        $excluded = array_filter([(int) $expense->recorded_by, (int) $expense->pl_reviewed_by]);

        return $this->projectMembers($expense->project_id, self::FINANCE)
            ->reject(fn (User $user) => in_array((int) $user->id, $excluded, true))
            ->values();
    }

    /** True when an approval request is an expense's verify step escalated to the Program Leader. */
    public static function isEscalation(ApprovalRequest $approval): bool
    {
        return $approval->type === 'expense' && ! empty(($approval->payload ?? [])['expense_id']);
    }

    /**
     * Approved, but no Finance/Admin of the project may verify it (the only
     * Finance/Admin recorded it, as with older expenses, or approved it): the
     * verify step goes to the Program Leader as a pending "expense" approval
     * request. The recorder is the requester, so a Program Leader who
     * recorded it cannot decide it. Returns the expense (fresh), unchanged
     * when this does not apply.
     */
    public function escalateVerifyIfNeeded(Expense $expense, User $actor): Expense
    {
        if ($expense->workflow_status !== 'endorsed' || $expense->pendingEscalation() !== null
            || $this->eligibleVerifiers($expense)->isNotEmpty() || $this->eligibleProgramVerifiers($expense)->isEmpty()) {
            return $expense;
        }

        $why = 'No other Finance/Admin of this project can verify this expense (they recorded or approved it), so the Program Leader verifies it.';
        $approval = ApprovalRequest::create([
            'project_id' => $expense->project_id,
            'type' => 'expense',
            'title' => 'Expense ' . ($expense->reference_no ?: 'EXP-' . $expense->id) . ': verify',
            'description' => $why,
            'justification' => $why,
            'amount' => round((float) $expense->amount, 2),
            'payload' => ['expense_id' => $expense->id, 'step' => 'verify'],
            'requested_by' => $expense->recorded_by ?: $actor->id,
            'status' => 'pending',
        ]);
        $expense->forceFill(['approval_request_id' => $approval->id])->save();

        return $expense->fresh();
    }

    /**
     * The Program Leader's decision on an escalated verify step (inside
     * ProgramDashboardService::decide()'s transaction). One audit entry, on
     * the expense: approved -> verified; returned -> back to the recorder.
     */
    public function decideEscalation(ApprovalRequest $approval, string $decision, ?string $remarks, User $actor): Expense
    {
        $expense = Expense::query()->whereKey(($approval->payload ?? [])['expense_id'] ?? 0)->lockForUpdate()->first();
        if (! $expense || (int) $expense->approval_request_id !== (int) $approval->id || $expense->workflow_status !== 'endorsed') {
            abort(409, 'This expense is no longer waiting for this decision.');
        }
        $this->requireNotMaker($expense, $actor, 'You cannot verify or reject an expense you recorded.');
        $this->requireNotEndorser($expense, $actor);

        if ($decision === 'approved') {
            return $this->move($expense, 'verified', $actor, 'verified', ['endorsed'], [
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'remarks' => 'Verified by ' . $actor->name . ' (Program Leader).',
            ], $remarks);
        }

        return $this->move($expense, 'returned', $actor, 'rejected', ['endorsed'], [
            'return_reason' => $remarks,
            'remarks' => $remarks,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ], $remarks);
    }

    /** Notifications for an escalated decision, after it is committed. */
    public function notifyEscalationDecision(ApprovalRequest $approval, Expense $expense, User $actor): void
    {
        $expense = $expense->fresh();
        $approved = $approval->status === 'approved';
        $this->notify($this->recorder($expense), $expense, $approved ? 'verified' : 'rejected', $actor, $approved ? null : $approval->review_notes);
    }

    /** Program Leaders who could verify: active, not the recorder, not the approver. */
    private function eligibleProgramVerifiers(Expense $expense): Collection
    {
        $excluded = array_filter([(int) $expense->recorded_by, (int) $expense->pl_reviewed_by]);

        return User::query()->where('is_active', true)
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))
            ->get()
            ->reject(fn (User $user) => in_array((int) $user->id, $excluded, true))
            ->values();
    }

    /** Waiting for verification: tell the eligible Finance/Admin, or the Program Leaders when escalated. */
    private function notifyVerifiers(Expense $expense, string $event, User $actor): void
    {
        $recipients = $expense->pendingEscalation() !== null
            ? $this->eligibleProgramVerifiers($expense)
            : $this->eligibleVerifiers($expense);
        $this->notify($recipients, $expense, $event, $actor);
    }

    private function requireNotEscalated(Expense $expense): void
    {
        if ($expense->pendingEscalation() !== null) {
            abort(403, 'No other Finance/Admin can verify this expense, so it is waiting for the Program Leader.');
        }
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

    /** Fields the recorder may set on a draft. */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'lib_category_id', 'funding_source', 'description', 'payee', 'reference_no', 'amount', 'expense_date',
        ]));
    }

    private function snapshot(Expense $expense): array
    {
        return $expense->only([
            'project_id', 'lib_category_id', 'funding_source', 'purchase_request_id', 'reference_no', 'payee', 'description', 'amount', 'expense_date', 'workflow_status',
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

<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\Concerns\RunsWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Payroll computation and the monthly approval (FR: automatic payroll
 * computation). One PayrollBatch per project and month groups the rows:
 *
 *   draft --submit--> submitted --endorse--> endorsed --verify--> verified
 *   draft/returned <------ return (Project Leader) / reject (Finance/Admin)
 *
 * The hand-offs act on the whole batch: one audit entry and one
 * notification per recipient per hand-off.
 *
 * Self-pay: nobody approves or verifies a batch that includes their own
 * pay. If it pays the Project Leader and no other Project Leader may
 * approve, the approve step goes to the Program Leader; if no Finance/Admin
 * without pay in it (and not its maker or approver) may verify, the verify
 * step goes to the Program Leader. Both are pending "payroll" approval
 * requests on the Program Leader Approvals page (decideEscalation()). Rows (one per person) are created and computed
 * with PayrollCalculator (config/payroll.php) and can be edited only while
 * the batch is draft or returned; each row change has its own audit entry.
 * The rows' workflow columns follow the batch. Payroll is not LIB spent.
 */
class PayrollService
{
    use RunsWorkflow;

    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'endorsed' => 'Approved by Project Leader',
        'returned' => 'Returned',
        'verified' => 'Verified',
    ];

    private const ENCODERS = ['research-assistant', 'finance-admin'];

    public function __construct(private PayrollCalculator $calculator)
    {
    }

    public function batchFor(Project $project, int $year, int $month): ?PayrollBatch
    {
        return PayrollBatch::query()
            ->where('project_id', $project->id)->where('pay_year', $year)->where('pay_month', $month)
            ->first();
    }

    // ---------------------------------------------------------------- rows

    /**
     * Create the month's batch (if needed) and one computed row per active
     * member with a monthly rate who has no row yet.
     *
     * @return Collection<int, Payroll> the rows created
     */
    public function createMonth(Project $project, int $year, int $month, LibCategory $lineItem, User $actor): Collection
    {
        $this->requireRole($project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can prepare payroll.');
        $existingBatch = $this->batchFor($project, $year, $month);
        if ($existingBatch) {
            $this->requireState($existingBatch, PayrollBatch::EDITABLE, 'changed');
        }

        $members = $this->ratedMembers($project);
        if ($members->isEmpty()) {
            throw ValidationException::withMessages([
                'month' => ['No one on this project has a monthly rate yet. Set pay rates first.'],
            ]);
        }

        $existing = Payroll::query()
            ->where('project_id', $project->id)->where('pay_year', $year)->where('pay_month', $month)
            ->pluck('personnel_id')->map(fn ($id) => (int) $id)->all();
        $toCreate = $members->reject(fn (ProjectUser $member) => in_array((int) $member->user_id, $existing, true))->values();
        if ($toCreate->isEmpty()) {
            throw ValidationException::withMessages([
                'month' => ['Payroll for ' . self::monthLabel($year, $month) . ' already exists for everyone with a pay rate.'],
            ]);
        }

        return AuditLogger::transaction(function () use ($toCreate, $project, $year, $month, $lineItem, $actor) {
            $batch = PayrollBatch::query()->firstOrCreate(
                ['project_id' => $project->id, 'pay_year' => $year, 'pay_month' => $month],
                ['prepared_by' => $actor->id, 'workflow_status' => 'draft']
            );
            $batch = $this->lockRecord($batch);
            $this->requireState($batch, PayrollBatch::EDITABLE, 'changed');

            return $toCreate->map(function (ProjectUser $member) use ($batch, $project, $year, $month, $lineItem, $actor) {
                $result = $this->calculator->compute((float) $member->monthly_rate, (float) $member->allowance);
                $payroll = Payroll::create([
                    'project_id' => $project->id,
                    'payroll_batch_id' => $batch->id,
                    'lib_category_id' => $lineItem->id,
                    'personnel_id' => $member->user_id,
                    'computed_by' => $actor->id,
                    'pay_month' => $month,
                    'pay_year' => $year,
                    'basic_rate' => $result['basic'],
                    'allowance' => $result['allowance'],
                    'gross_pay' => $result['gross'],
                    'total_deductions' => $result['total_deductions'],
                    'net_pay' => $result['net'],
                    'workflow_status' => $batch->workflow_status,
                    'status' => Payroll::STATUS_FOR_WORKFLOW[$batch->workflow_status],
                ]);
                $this->storeDeductions($payroll, $result['deductions']);
                AuditLogger::write('created', $payroll, [], $this->money($payroll), $actor);

                return $payroll->fresh();
            });
        });
    }

    /**
     * Recompute every row of the month's batch from the current pay rates and
     * deduction rules (batch draft or returned only).
     *
     * @return Collection<int, Payroll>
     */
    public function computeMonth(Project $project, int $year, int $month, User $actor): Collection
    {
        $this->requireRole($project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can compute payroll.');

        $batch = $this->batchFor($project, $year, $month);
        if (! $batch || ! $batch->rows()->exists()) {
            throw ValidationException::withMessages([
                'month' => ['There is no payroll for ' . self::monthLabel($year, $month) . ' to compute.'],
            ]);
        }
        $this->requireState($batch, PayrollBatch::EDITABLE, 'computed');

        $rates = ProjectUser::query()->where('project_id', $project->id)->get()->keyBy('user_id');

        return AuditLogger::transaction(function () use ($batch, $rates, $actor) {
            $this->requireState($this->lockRecord($batch), PayrollBatch::EDITABLE, 'computed');

            return $batch->rows()->orderBy('id')->get()->map(function (Payroll $row) use ($rates, $actor) {
                $locked = $this->lockRecord($row);
                $rate = $rates->get($locked->personnel_id);
                $hasRate = $rate && $rate->monthly_rate !== null;

                return $this->recompute(
                    $locked,
                    $hasRate ? (float) $rate->monthly_rate : (float) $locked->basic_rate,
                    $hasRate ? (float) $rate->allowance : (float) $locked->allowance,
                    [],
                    'computed',
                    $actor
                );
            });
        });
    }

    public function update(Payroll $payroll, array $data, User $actor): Payroll
    {
        $this->requireRole($payroll->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can edit payroll.');
        $this->requireState($payroll->batch, PayrollBatch::EDITABLE, 'edited');

        return AuditLogger::transaction(function () use ($payroll, $data, $actor) {
            $this->requireState($this->lockRecord($payroll->batch), PayrollBatch::EDITABLE, 'edited');
            $locked = $this->lockRecord($payroll);

            return $this->recompute(
                $locked,
                array_key_exists('basic_rate', $data) ? (float) $data['basic_rate'] : (float) $locked->basic_rate,
                array_key_exists('allowance', $data) ? (float) $data['allowance'] : (float) $locked->allowance,
                array_intersect_key($data, array_flip(['lib_category_id'])),
                'updated',
                $actor
            );
        });
    }

    // ---------------------------------------------------------------- batch hand-offs

    /**
     * Submit the month. When the batch pays a Project Leader of the project
     * and no other Project Leader may approve it, the approve step goes to
     * the Program Leader as a pending "payroll" approval request.
     */
    public function submit(PayrollBatch $batch, User $actor): PayrollBatch
    {
        $this->requireRole($batch->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can submit payroll.');
        $this->requireState($batch, PayrollBatch::EDITABLE, 'submitted');
        if (! $batch->rows()->exists()) {
            throw ValidationException::withMessages(['rows' => ['There is no payroll in this batch to submit.']]);
        }

        $paidLeaders = $this->paidLeaders($batch);
        $escalate = $paidLeaders->isNotEmpty() && $this->eligibleLeaders($batch)->isEmpty();

        $batch = AuditLogger::transaction(function () use ($batch, $actor, $escalate, $paidLeaders) {
            $approval = $escalate ? $this->escalate($batch, 'approve', $actor, sprintf(
                'The %s payroll includes pay for the Project Leader (%s), so the Program Leader approves it.',
                $batch->monthLabel(),
                $paidLeaders->pluck('name')->join(', ')
            )) : null;

            return $this->move($batch, 'submitted', $actor, 'submitted', PayrollBatch::EDITABLE, [
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
                'return_reason' => null,
                'pl_reviewed_by' => null,
                'pl_reviewed_at' => null,
                'approval_request_id' => $approval?->id,
            ]);
        });

        if ($escalate) {
            $this->notify($this->programLeaders(), $batch, 'submitted', $actor, null, [
                'message' => "{$actor->name} submitted the {$batch->monthLabel()} payroll. It includes pay for the Project Leader, so it comes to you for approval.",
                'link' => '/program-approvals?project=' . $batch->project_id,
            ]);
            $this->notify($paidLeaders, $batch, 'escalated', $actor, null, [
                'title' => 'Payroll sent to the Program Leader',
                'message' => "The {$batch->monthLabel()} payroll includes your pay. Waiting for Program Leader (includes your pay).",
            ]);
        } else {
            $this->notify($this->projectMembers($batch->project_id, 'project-leader'), $batch, 'submitted', $actor);
        }

        return $batch;
    }

    public function endorse(PayrollBatch $batch, User $actor): PayrollBatch
    {
        $this->checkLeader($batch, $actor, 'approve');
        $this->requireState($batch, ['submitted'], 'approved');

        $batch = AuditLogger::transaction(function () use ($batch, $actor) {
            $moved = $this->move($batch, 'endorsed', $actor, 'endorsed', ['submitted'], [
                'pl_reviewed_by' => $actor->id,
                'pl_reviewed_at' => now(),
            ]);

            return $this->escalateVerifyIfNeeded($moved, $actor);
        });

        $this->notifyVerifiers($batch, $actor);

        return $batch;
    }

    public function returnToPreparer(PayrollBatch $batch, string $reason, User $actor): PayrollBatch
    {
        $this->checkLeader($batch, $actor, 'return');
        $this->requireState($batch, ['submitted'], 'returned');
        $reason = $this->requireReason($reason, 'A reason is required to return a payroll.');

        $batch = $this->move($batch, 'returned', $actor, 'returned', ['submitted'], [
            'return_reason' => $reason,
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ], $reason);

        $this->notify($this->activeUser($batch->submitted_by), $batch, 'returned', $actor, $reason);

        return $batch;
    }

    public function verify(PayrollBatch $batch, User $actor): PayrollBatch
    {
        $this->checkVerifier($batch, $actor, 'verify');
        $this->requireState($batch, ['endorsed'], 'verified');

        $batch = $this->move($batch, 'verified', $actor, 'verified', ['endorsed'], [
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ]);

        $this->notify($this->activeUser($batch->submitted_by), $batch, 'verified', $actor);

        return $batch;
    }

    public function reject(PayrollBatch $batch, string $reason, User $actor): PayrollBatch
    {
        $this->checkVerifier($batch, $actor, 'reject');
        $this->requireState($batch, ['endorsed'], 'rejected');
        $reason = $this->requireReason($reason, 'A reason is required to reject a payroll.');

        // Rejected by Finance/Admin = sent back as returned.
        $batch = $this->move($batch, 'returned', $actor, 'rejected', ['endorsed'], [
            'return_reason' => $reason,
        ], $reason);

        $this->notify($this->activeUser($batch->submitted_by), $batch, 'rejected', $actor, $reason);

        return $batch;
    }

    // ---------------------------------------------------------------- Program Leader escalation

    /** True when an approval request is a payroll batch step escalated to the Program Leader. */
    public static function isEscalation(ApprovalRequest $approval): bool
    {
        return $approval->type === 'payroll' && ! empty(($approval->payload ?? [])['payroll_batch_id']);
    }

    /**
     * The Program Leader's decision on an escalated step (called inside
     * ProgramDashboardService::decide()'s transaction). One audit entry, on
     * the batch:
     *   approve step: approved -> endorsed, rejected -> returned
     *   verify step:  approved -> verified, rejected -> returned
     */
    public function decideEscalation(ApprovalRequest $approval, string $decision, ?string $remarks, User $actor): PayrollBatch
    {
        $payload = $approval->payload ?? [];
        $batch = PayrollBatch::query()->whereKey($payload['payroll_batch_id'] ?? 0)->lockForUpdate()->first();
        $step = $payload['step'] ?? 'approve';
        $expected = $step === 'verify' ? 'endorsed' : 'submitted';

        if (! $batch || (int) $batch->approval_request_id !== (int) $approval->id || $batch->workflow_status !== $expected) {
            abort(409, 'This payroll is no longer waiting for this decision.');
        }
        $this->requireNotMaker($batch, $actor, 'You cannot approve or verify a payroll you prepared.');
        if (in_array((int) $actor->id, $batch->paidUserIds(), true)) {
            abort(403, 'You cannot decide on a payroll that includes your pay.');
        }

        if ($step === 'approve') {
            if ($decision === 'approved') {
                $moved = $this->move($batch, 'endorsed', $actor, 'approved', ['submitted'], [
                    'pl_reviewed_by' => $actor->id,
                    'pl_reviewed_at' => now(),
                ], $remarks);

                return $this->escalateVerifyIfNeeded($moved, $actor);
            }

            return $this->move($batch, 'returned', $actor, 'returned', ['submitted'], [
                'return_reason' => $remarks,
                'pl_reviewed_by' => $actor->id,
                'pl_reviewed_at' => now(),
            ], $remarks);
        }

        $this->requireNotSame($batch->pl_reviewed_by, $actor, 'You approved this payroll; another person must verify it.');

        return $decision === 'approved'
            ? $this->move($batch, 'verified', $actor, 'verified', ['endorsed'], ['verified_by' => $actor->id, 'verified_at' => now()], $remarks)
            : $this->move($batch, 'returned', $actor, 'rejected', ['endorsed'], ['return_reason' => $remarks], $remarks);
    }

    /** Notifications for an escalated decision, after it is committed. */
    public function notifyEscalationDecision(ApprovalRequest $approval, PayrollBatch $batch, User $actor): void
    {
        $step = ($approval->payload ?? [])['step'] ?? 'approve';
        $batch = $batch->fresh();

        if ($approval->status === 'approved' && $step === 'approve') {
            $this->notifyVerifiers($batch, $actor);
        } elseif ($approval->status === 'approved') {
            $this->notify($this->activeUser($batch->submitted_by), $batch, 'verified', $actor);
        } else {
            $this->notify($this->activeUser($batch->submitted_by), $batch, $step === 'verify' ? 'rejected' : 'returned', $actor, $approval->review_notes);
        }
    }

    /**
     * What the batch is waiting for, worded for the viewer (null when it is
     * not waiting for anyone).
     */
    public function waitingText(PayrollBatch $batch, ?User $viewer = null): ?string
    {
        $paid = $viewer && in_array((int) $viewer->id, $batch->paidUserIds(), true);
        $escalated = $batch->pendingEscalation() !== null;

        if ($batch->workflow_status === 'submitted') {
            if ($escalated) {
                return $paid ? 'Waiting for Program Leader (includes your pay)' : "Waiting for Program Leader (includes the Project Leader's pay)";
            }

            return 'Waiting for the Project Leader';
        }

        if ($batch->workflow_status === 'endorsed') {
            if ($escalated) {
                return 'Waiting for the Program Leader to verify (no other Finance/Admin can)';
            }
            if ($this->isVerificationBlocked($batch)) {
                return 'Waiting for verification. ' . self::NO_VERIFIER;
            }
            if ($paid && $viewer && $batch->project && $viewer->roleOn($batch->project) === 'finance-admin') {
                return 'Waiting for another Finance/Admin (includes your pay)';
            }

            return 'Waiting for Finance/Admin';
        }

        return null;
    }

    /** Whether this user may verify / reject the batch now (Finance/Admin path). */
    public function canVerify(PayrollBatch $batch, User $user): bool
    {
        if ($batch->workflow_status !== 'endorsed') {
            return false;
        }
        try {
            $this->checkVerifier($batch, $user, 'verify');

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        }
    }

    // ---------------------------------------------------------------- helpers

    public static function monthLabel(int $year, int $month): string
    {
        return Carbon::create($year, $month, 1)->format('F Y');
    }

    /** Active members of the project with a monthly rate, one per person. */
    public function ratedMembers(Project $project): Collection
    {
        return ProjectUser::query()
            ->where('project_id', $project->id)
            ->whereNotNull('monthly_rate')
            ->with('user')
            ->orderBy('id')
            ->get()
            ->filter(fn (ProjectUser $member) => $member->user && $member->user->is_active)
            ->unique('user_id')
            ->values();
    }

    protected function stageLabels(): array
    {
        return self::LABELS;
    }

    protected function noun(): string
    {
        return 'payroll';
    }

    private function checkLeader(PayrollBatch $batch, User $actor, string $verb): void
    {
        $this->requireRole($batch->project, $actor, ['project-leader'], "Only the Project Leader of this project can {$verb} payroll.");
        $this->requireNotMaker($batch, $actor, 'You cannot approve or return a payroll you prepared.');

        $paid = in_array((int) $actor->id, $batch->paidUserIds(), true);
        if ($batch->pendingEscalation()) {
            abort(403, $paid ? 'Waiting for Program Leader (includes your pay).' : 'This payroll is waiting for the Program Leader.');
        }
        if ($paid) {
            abort(403, 'You cannot approve or return a payroll that includes your pay.');
        }
    }

    /**
     * Finance/Admin of the project who did not prepare, approve or get paid
     * in the batch. (When there is none, the verify step is escalated to the
     * Program Leader and decided on the Approvals page.)
     */
    private function checkVerifier(PayrollBatch $batch, User $actor, string $verb): void
    {
        $this->requireRole($batch->project, $actor, ['finance-admin'], "Only Finance/Admin staff of this project can {$verb} payroll.");
        $this->requireNotMaker($batch, $actor, 'You cannot verify or reject a payroll you prepared.');
        $this->requireNotSame($batch->pl_reviewed_by, $actor, 'You approved this payroll as Project Leader; another person must verify it.');
        if (in_array((int) $actor->id, $batch->paidUserIds(), true)) {
            abort(403, 'You cannot verify or reject a payroll that includes your pay.');
        }
    }

    /** Project Leaders of the project who are paid in the batch. */
    private function paidLeaders(PayrollBatch $batch): Collection
    {
        $paid = $batch->paidUserIds();

        return $this->projectMembers($batch->project_id, 'project-leader')
            ->filter(fn (User $user) => in_array((int) $user->id, $paid, true))
            ->values();
    }

    /** Project Leaders who may approve: not a maker, not paid in the batch. */
    private function eligibleLeaders(PayrollBatch $batch): Collection
    {
        $excluded = array_merge($batch->makerIds(), $batch->paidUserIds());

        return $this->projectMembers($batch->project_id, 'project-leader')
            ->reject(fn (User $user) => in_array((int) $user->id, $excluded, true))
            ->values();
    }

    /** Finance/Admin who may verify: not a maker, not the approver, not paid. */
    private function eligibleVerifiers(PayrollBatch $batch): Collection
    {
        $excluded = array_merge($batch->makerIds(), $batch->paidUserIds(), array_filter([(int) $batch->pl_reviewed_by]));

        return $this->projectMembers($batch->project_id, 'finance-admin')
            ->reject(fn (User $user) => in_array((int) $user->id, $excluded, true))
            ->values();
    }

    private function programLeaders(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))
            ->get();
    }

    /**
     * After approval: if no Finance/Admin may verify, escalate the verify
     * step to the Program Leader (the approver is recorded as the requester,
     * so they cannot decide it themselves).
     */
    private function escalateVerifyIfNeeded(PayrollBatch $batch, User $approver): PayrollBatch
    {
        // A Finance/Admin can verify; or nobody can (blocked, see
        // isVerificationBlocked()): no escalation either way.
        if ($this->eligibleVerifiers($batch)->isNotEmpty() || $this->eligibleProgramVerifiers($batch)->isEmpty()) {
            return $batch;
        }

        $approval = $this->escalate($batch, 'verify', $approver, sprintf(
            'No other Finance/Admin staff of this project can verify the %s payroll (they prepared or approved it, or it includes their pay), so the Program Leader verifies it.',
            $batch->monthLabel()
        ));
        $batch->update(['approval_request_id' => $approval->id]);

        return $batch->fresh();
    }

    /** A pending "payroll" approval request for one step of the batch. */
    private function escalate(PayrollBatch $batch, string $step, User $requestedBy, string $why): ApprovalRequest
    {
        return ApprovalRequest::create([
            'project_id' => $batch->project_id,
            'type' => 'payroll',
            'title' => 'Payroll ' . $batch->monthLabel() . ($step === 'verify' ? ': verify' : ': approve'),
            'description' => $why,
            'justification' => $why,
            'amount' => round((float) $batch->rows()->sum('gross_pay'), 2),
            'payload' => ['payroll_batch_id' => $batch->id, 'step' => $step, 'month' => $batch->monthKey()],
            'requested_by' => $requestedBy->id,
            'status' => 'pending',
        ]);
    }

    /** After approval: the eligible Finance/Admin, or the Program Leader when escalated. */
    public const NO_VERIFIER = 'No eligible verifier. Assign a Finance/Admin who is not paid in this batch to the project.';

    /**
     * Approved, but nobody may verify it: no Finance/Admin of the project who
     * did not prepare, approve or get paid in it, and no Program Leader who
     * did not approve, prepare or get paid in it (the approver never
     * verifies, even as Program Leader). It waits until an eligible
     * Finance/Admin is assigned to the project.
     */
    public function isVerificationBlocked(PayrollBatch $batch): bool
    {
        return $batch->workflow_status === 'endorsed'
            && $batch->pendingEscalation() === null
            && $this->eligibleVerifiers($batch)->isEmpty();
    }

    /** Program Leaders who could verify: not the approver, not a maker, not paid. */
    private function eligibleProgramVerifiers(PayrollBatch $batch): Collection
    {
        $excluded = array_merge($batch->makerIds(), $batch->paidUserIds(), array_filter([(int) $batch->pl_reviewed_by]));

        return $this->programLeaders()
            ->reject(fn (User $user) => in_array((int) $user->id, $excluded, true))
            ->values();
    }

    private function notifyVerifiers(PayrollBatch $batch, User $actor): void
    {
        if ($this->isVerificationBlocked($batch)) {
            // Once, to every Program Leader, the approver included (the usual
            // "never the actor" rule would hide this from the person who must act).
            $this->notifyBlocked($this->programLeaders(), $batch, $actor);

            return;
        }

        if ($batch->pendingEscalation()) {
            $this->notify($this->programLeaders()->reject(fn (User $u) => (int) $u->id === (int) $batch->pl_reviewed_by), $batch, 'endorsed', $actor, null, [
                'title' => 'Payroll waiting for your verification',
                'message' => "The {$batch->monthLabel()} payroll was approved; no other Finance/Admin of the project can verify it, so it comes to you.",
                'link' => '/program-approvals?project=' . $batch->project_id,
            ]);

            return;
        }

        $this->notify($this->eligibleVerifiers($batch), $batch, 'endorsed', $actor);
    }

    private function notifyBlocked(Collection $recipients, PayrollBatch $batch, User $actor): void
    {
        if ($recipients->isEmpty()) {
            return;
        }
        $batch->loadMissing('project');

        try {
            \Illuminate\Support\Facades\Notification::send($recipients, new WorkflowNotification([
                'kind' => 'payroll',
                'event' => 'verification_blocked',
                'title' => 'Payroll cannot be verified yet',
                'message' => "The {$batch->monthLabel()} payroll of {$batch->project?->title} is waiting for verification. " . self::NO_VERIFIER,
                'payroll_batch_id' => $batch->id,
                'project_id' => $batch->project_id,
                'project' => $batch->project?->title,
                'actor' => $actor->name,
                'reason' => null,
                'link' => '/program-users',
            ]));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** Maker = anyone who prepared, computed or submitted the batch. */
    private function requireNotMaker(PayrollBatch $batch, User $actor, string $message): void
    {
        if (in_array((int) $actor->id, $batch->makerIds(), true)) {
            abort(403, $message);
        }
    }

    /**
     * Move the batch with one audit entry and bring its rows to the same
     * stage (no per-row audit entries), in one transaction.
     */
    private function move(PayrollBatch $batch, string $to, User $actor, string $auditAction, array $from, array $extra, ?string $reason = null): PayrollBatch
    {
        $verb = $auditAction === 'endorsed' ? 'approved' : $auditAction;

        return AuditLogger::transaction(function () use ($batch, $to, $actor, $auditAction, $from, $verb, $extra, $reason) {
            $moved = $this->moveRecord($batch, $to, $actor, $auditAction, $from, $verb, $extra, $reason);

            // The rows mirror the batch (query update: no per-row audit).
            Payroll::query()->where('payroll_batch_id', $moved->id)->update([
                'workflow_status' => $to,
                'status' => Payroll::STATUS_FOR_WORKFLOW[$to],
                'submitted_at' => $moved->submitted_at,
                'pl_reviewed_by' => $moved->pl_reviewed_by,
                'pl_reviewed_at' => $moved->pl_reviewed_at,
                'verified_by' => $moved->verified_by,
                'verified_at' => $moved->verified_at,
                'return_reason' => $moved->return_reason,
                'updated_at' => now(),
            ]);

            return $moved;
        });
    }

    /** Recompute one locked row with one audit entry (old / new money fields). */
    private function recompute(Payroll $locked, float $basic, float $allowance, array $extra, string $auditAction, User $actor): Payroll
    {
        $result = $this->calculator->compute($basic, $allowance);
        $old = $this->money($locked);

        $locked->update(array_merge($extra, [
            // Whoever last computed or edited the row is one of its makers.
            'computed_by' => $actor->id,
            'basic_rate' => $result['basic'],
            'allowance' => $result['allowance'],
            'gross_pay' => $result['gross'],
            'total_deductions' => $result['total_deductions'],
            'net_pay' => $result['net'],
        ]));
        $this->storeDeductions($locked, $result['deductions']);

        AuditLogger::write($auditAction, $locked, $old, $this->money($locked->fresh()), $actor);

        return $locked->fresh();
    }

    private function storeDeductions(Payroll $payroll, array $deductions): void
    {
        $payroll->deductions()->delete();
        foreach ($deductions as $deduction) {
            $payroll->deductions()->create(['deduction_type' => $deduction['type'], 'amount' => $deduction['amount']]);
        }
    }

    private function money(Payroll $payroll): array
    {
        return [
            'project_id' => $payroll->project_id,
            'personnel_id' => $payroll->personnel_id,
            'pay_month' => $payroll->pay_month,
            'pay_year' => $payroll->pay_year,
            'lib_category_id' => $payroll->lib_category_id,
            'basic_rate' => (float) $payroll->basic_rate,
            'allowance' => (float) $payroll->allowance,
            'gross_pay' => (float) $payroll->gross_pay,
            'total_deductions' => (float) $payroll->total_deductions,
            'net_pay' => (float) $payroll->net_pay,
        ];
    }

    /** One notification per recipient for the whole batch. */
    private function notify(Collection $recipients, PayrollBatch $batch, string $event, User $actor, ?string $reason = null, array $override = []): void
    {
        $batch->loadMissing('project');
        $period = $batch->monthLabel();
        $count = $batch->rows()->count();
        $people = $count . ' ' . ($count === 1 ? 'person' : 'people');
        $net = '₱' . number_format((float) $batch->rows()->sum('net_pay'), 2);
        $because = $reason ? ' Reason: ' . $reason : '';

        [$title, $message] = match ($event) {
            'submitted' => ['Payroll waiting for your approval', "{$actor->name} submitted the {$period} payroll ({$people}, net {$net})."],
            'endorsed' => ['Payroll ready for verification', "{$actor->name} (Project Leader) approved the {$period} payroll ({$people}, net {$net})."],
            'returned' => ['Payroll returned to you', "{$actor->name} returned the {$period} payroll.{$because}"],
            'rejected' => ['Payroll rejected', "{$actor->name} rejected the {$period} payroll and sent it back.{$because}"],
            'verified' => ['Payroll verified', "{$actor->name} verified the {$period} payroll ({$people}, net {$net})."],
            'escalated' => ['Payroll sent to the Program Leader', "The {$period} payroll is waiting for the Program Leader."],
        };

        $this->notifyUsers($recipients, new WorkflowNotification(array_merge([
            'kind' => 'payroll',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'payroll_batch_id' => $batch->id,
            'project_id' => $batch->project_id,
            'project' => $batch->project?->title,
            'actor' => $actor->name,
            'reason' => $reason,
            'link' => '/payroll?project=' . $batch->project_id . '&month=' . $batch->monthKey(),
        ], $override)), $actor);
    }
}

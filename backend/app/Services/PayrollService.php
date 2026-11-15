<?php

namespace App\Services;

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
 * notification per hand-off. Rows (one per person) are created and computed
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

    public function submit(PayrollBatch $batch, User $actor): PayrollBatch
    {
        $this->requireRole($batch->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can submit payroll.');
        $this->requireState($batch, PayrollBatch::EDITABLE, 'submitted');
        if (! $batch->rows()->exists()) {
            throw ValidationException::withMessages(['rows' => ['There is no payroll in this batch to submit.']]);
        }

        $batch = $this->move($batch, 'submitted', $actor, 'submitted', PayrollBatch::EDITABLE, [
            'submitted_at' => now(),
            'submitted_by' => $actor->id,
            'return_reason' => null,
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ]);

        $this->notify($this->projectMembers($batch->project_id, 'project-leader'), $batch, 'submitted', $actor);

        return $batch;
    }

    public function endorse(PayrollBatch $batch, User $actor): PayrollBatch
    {
        $this->checkLeader($batch, $actor, 'approve');
        $this->requireState($batch, ['submitted'], 'approved');

        $batch = $this->move($batch, 'endorsed', $actor, 'endorsed', ['submitted'], [
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ]);

        $this->notify($this->projectMembers($batch->project_id, 'finance-admin'), $batch, 'endorsed', $actor);

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
    }

    private function checkVerifier(PayrollBatch $batch, User $actor, string $verb): void
    {
        $this->requireRole($batch->project, $actor, ['finance-admin'], "Only Finance/Admin staff of this project can {$verb} payroll.");
        $this->requireNotMaker($batch, $actor, 'You cannot verify or reject a payroll you prepared.');
        $this->requireNotSame($batch->pl_reviewed_by, $actor, 'You approved this payroll as Project Leader; another person must verify it.');
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
    private function notify(Collection $recipients, PayrollBatch $batch, string $event, User $actor, ?string $reason = null): void
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
        };

        $this->notifyUsers($recipients, new WorkflowNotification([
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
        ]), $actor);
    }
}

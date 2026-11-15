<?php

namespace App\Services;

use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\Concerns\RunsWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Payroll computation and workflow (FR: automatic payroll computation).
 *
 *   draft --submit--> submitted --endorse--> endorsed --verify--> verified
 *   draft/returned <------ return (Project Leader) / reject (Finance/Admin)
 *
 * "Create for a month" makes one draft row per active project member who
 * has a monthly rate (project_user.monthly_rate) and computes it with
 * PayrollCalculator (config/payroll.php). Payroll is not LIB spent.
 * Each change writes exactly one audit entry per payroll row.
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
    private const EDITABLE = ['draft', 'returned'];

    public function __construct(private PayrollCalculator $calculator)
    {
    }

    // ---------------------------------------------------------------- compute

    /** @return Collection<int, Payroll> the rows created */
    public function createMonth(Project $project, int $year, int $month, LibCategory $lineItem, User $actor): Collection
    {
        $this->requireRole($project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can prepare payroll.');

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
            return $toCreate->map(function (ProjectUser $member) use ($project, $year, $month, $lineItem, $actor) {
                $result = $this->calculator->compute((float) $member->monthly_rate, (float) $member->allowance);
                $payroll = Payroll::create([
                    'project_id' => $project->id,
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
                    'status' => Payroll::STATUS_FOR_WORKFLOW['draft'],
                    'workflow_status' => 'draft',
                ]);
                $this->storeDeductions($payroll, $result['deductions']);
                AuditLogger::write('created', $payroll, [], $this->money($payroll), $actor);

                return $payroll->fresh();
            });
        });
    }

    /**
     * Recompute every draft / returned row of the month from the current pay
     * rates and deduction rules.
     *
     * @return Collection<int, Payroll>
     */
    public function computeMonth(Project $project, int $year, int $month, User $actor): Collection
    {
        $this->requireRole($project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can compute payroll.');

        $rows = Payroll::query()
            ->where('project_id', $project->id)->where('pay_year', $year)->where('pay_month', $month)
            ->whereIn('workflow_status', self::EDITABLE)
            ->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'month' => ['There is no draft or returned payroll for ' . self::monthLabel($year, $month) . ' to compute.'],
            ]);
        }

        $rates = ProjectUser::query()->where('project_id', $project->id)->get()->keyBy('user_id');

        return AuditLogger::transaction(function () use ($rows, $rates, $actor) {
            return $rows->map(function (Payroll $row) use ($rates, $actor) {
                $locked = $this->lockRecord($row);
                if (! in_array($locked->workflow_status, self::EDITABLE, true)) {
                    return $locked; // moved on meanwhile; left as it is
                }
                $rate = $rates->get($locked->personnel_id);
                $basic = $rate && $rate->monthly_rate !== null ? (float) $rate->monthly_rate : (float) $locked->basic_rate;
                $allowance = $rate && $rate->monthly_rate !== null ? (float) $rate->allowance : (float) $locked->allowance;

                return $this->recompute($locked, $basic, $allowance, [], 'computed', $actor);
            });
        });
    }

    public function update(Payroll $payroll, array $data, User $actor): Payroll
    {
        $this->requireRole($payroll->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can edit payroll.');
        $this->requireState($payroll, self::EDITABLE, 'edited');

        return AuditLogger::transaction(function () use ($payroll, $data, $actor) {
            $locked = $this->lockRecord($payroll);
            $this->requireState($locked, self::EDITABLE, 'edited');

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

    // ---------------------------------------------------------------- hand-offs

    public function submit(Payroll $payroll, User $actor): Payroll
    {
        $this->requireRole($payroll->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can submit payroll.');
        $this->requireState($payroll, self::EDITABLE, 'submitted');

        $payroll = $this->move($payroll, 'submitted', $actor, 'submitted', self::EDITABLE, [
            'submitted_at' => now(),
            'return_reason' => null,
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ]);

        $this->notify($this->projectMembers($payroll->project_id, 'project-leader'), $payroll, 'submitted', $actor);

        return $payroll;
    }

    public function endorse(Payroll $payroll, User $actor): Payroll
    {
        $this->requireRole($payroll->project, $actor, ['project-leader'], 'Only the Project Leader of this project can approve payroll.');
        $this->requireNotSame($payroll->computed_by, $actor, 'You cannot approve or return a payroll you computed.');
        $this->requireNotSame($payroll->personnel_id, $actor, 'You cannot approve or return your own payroll.');
        $this->requireState($payroll, ['submitted'], 'approved');

        $payroll = $this->move($payroll, 'endorsed', $actor, 'endorsed', ['submitted'], [
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ]);

        $this->notify($this->projectMembers($payroll->project_id, 'finance-admin'), $payroll, 'endorsed', $actor);

        return $payroll;
    }

    public function returnToEncoder(Payroll $payroll, string $reason, User $actor): Payroll
    {
        $this->requireRole($payroll->project, $actor, ['project-leader'], 'Only the Project Leader of this project can return payroll.');
        $this->requireNotSame($payroll->computed_by, $actor, 'You cannot approve or return a payroll you computed.');
        $this->requireNotSame($payroll->personnel_id, $actor, 'You cannot approve or return your own payroll.');
        $this->requireState($payroll, ['submitted'], 'returned');
        $reason = $this->requireReason($reason, 'A reason is required to return a payroll.');

        $payroll = $this->move($payroll, 'returned', $actor, 'returned', ['submitted'], [
            'return_reason' => $reason,
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ], $reason);

        $this->notify($this->activeUser($payroll->computed_by), $payroll, 'returned', $actor, $reason);

        return $payroll;
    }

    public function verify(Payroll $payroll, User $actor): Payroll
    {
        $this->checkVerifier($payroll, $actor, 'verify');
        $this->requireState($payroll, ['endorsed'], 'verified');

        $payroll = $this->move($payroll, 'verified', $actor, 'verified', ['endorsed'], [
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ]);

        $this->notify($this->activeUser($payroll->computed_by), $payroll, 'verified', $actor);

        return $payroll;
    }

    public function reject(Payroll $payroll, string $reason, User $actor): Payroll
    {
        $this->checkVerifier($payroll, $actor, 'reject');
        $this->requireState($payroll, ['endorsed'], 'rejected');
        $reason = $this->requireReason($reason, 'A reason is required to reject a payroll.');

        // Rejected by Finance/Admin = sent back as returned.
        $payroll = $this->move($payroll, 'returned', $actor, 'rejected', ['endorsed'], [
            'return_reason' => $reason,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ], $reason);

        $this->notify($this->activeUser($payroll->computed_by), $payroll, 'rejected', $actor, $reason);

        return $payroll;
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

    private function checkVerifier(Payroll $payroll, User $actor, string $verb): void
    {
        $this->requireRole($payroll->project, $actor, ['finance-admin'], "Only Finance/Admin staff of this project can {$verb} payroll.");
        $this->requireNotSame($payroll->computed_by, $actor, 'You cannot verify or reject a payroll you computed.');
        $this->requireNotSame($payroll->personnel_id, $actor, 'You cannot verify or reject your own payroll.');
        $this->requireNotSame($payroll->pl_reviewed_by, $actor, 'You approved this payroll as Project Leader; another person must verify it.');
    }

    private function move(Payroll $payroll, string $to, User $actor, string $auditAction, array $from, array $extra, ?string $reason = null): Payroll
    {
        $verb = $auditAction === 'endorsed' ? 'approved' : $auditAction;

        return $this->moveRecord($payroll, $to, $actor, $auditAction, $from, $verb, $extra, $reason, Payroll::STATUS_FOR_WORKFLOW);
    }

    /** Recompute one locked row with one audit entry (old / new money fields). */
    private function recompute(Payroll $locked, float $basic, float $allowance, array $extra, string $auditAction, User $actor): Payroll
    {
        $result = $this->calculator->compute($basic, $allowance);
        $old = $this->money($locked);

        $locked->update(array_merge($extra, [
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

    private function notify(Collection $recipients, Payroll $payroll, string $event, User $actor, ?string $reason = null): void
    {
        $payroll->loadMissing(['project', 'personnel']);
        $who = $payroll->personnel?->name ?? 'a staff member';
        $period = self::monthLabel((int) $payroll->pay_year, (int) $payroll->pay_month);
        $net = '₱' . number_format((float) $payroll->net_pay, 2);
        $because = $reason ? ' Reason: ' . $reason : '';

        [$title, $message] = match ($event) {
            'submitted' => ['Payroll waiting for your approval', "{$actor->name} submitted the {$period} payroll of {$who} (net {$net})."],
            'endorsed' => ['Payroll ready for verification', "{$actor->name} (Project Leader) approved the {$period} payroll of {$who} (net {$net})."],
            'returned' => ['Payroll returned to you', "{$actor->name} returned the {$period} payroll of {$who}.{$because}"],
            'rejected' => ['Payroll rejected', "{$actor->name} rejected the {$period} payroll of {$who} and sent it back.{$because}"],
            'verified' => ['Payroll verified', "{$actor->name} verified the {$period} payroll of {$who} (net {$net})."],
        };

        $this->notifyUsers($recipients, new WorkflowNotification([
            'kind' => 'payroll',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'payroll_id' => $payroll->id,
            'project_id' => $payroll->project_id,
            'project' => $payroll->project?->title,
            'actor' => $actor->name,
            'reason' => $reason,
            'link' => '/payroll?project=' . $payroll->project_id . '&month=' . $payroll->monthKey(),
        ]), $actor);
    }
}

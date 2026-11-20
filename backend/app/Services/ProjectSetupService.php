<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Support\LineItemComputation;
use Illuminate\Validation\ValidationException;

/**
 * Program Leader set-up of a project: details and Project Leader, the
 * Line-Item Budget (PS and MOOE) and the planned tranches. Every call writes
 * exactly one audit entry (the model observers are muted inside
 * AuditLogger::transaction), and nothing is written when nothing changes.
 *
 * Rules:
 * - Line items never add up to more than the project's total budget.
 * - A line item with any expense, payroll row or purchase request cannot be
 *   removed, its category cannot change, and its amount cannot go below what
 *   is spent plus pending on it.
 * - After the project's first verified expense, allocated amounts of
 *   existing line items change only through a realignment request.
 * - Tranches are edited or removed only while not released and with no
 *   pending fund release request; releasing stays in that approval.
 */
class ProjectSetupService
{
    public const REALIGNMENT_ONLY = 'This project already has verified expenses or payroll. Change allocated amounts through a realignment request.';

    public function __construct(private ProjectFinancialService $financials)
    {
    }

    // ---------------------------------------------------------------- project

    public function createProject(array $data, User $actor): Project
    {
        $leader = $this->leaderOrFail($data['project_leader_id']);
        $this->requireGrantWithinBudget($data, (float) $data['total_approved_budget']);

        return AuditLogger::transaction(function () use ($data, $leader, $actor) {
            $project = Project::create([
                'title' => $data['title'],
                'code' => $data['code'],
                'funding_agency_id' => $data['funding_agency_id'],
                'funder_reference' => $data['funder_reference'] ?? null,
                'total_approved_budget' => $data['total_approved_budget'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
            ] + $this->moaValues($data));
            $this->setLeader($project, $leader);

            AuditLogger::write('created', $project, [], $this->projectSnapshot($project->fresh()), $actor);

            return $project->fresh();
        });
    }

    /**
     * The MOA fields present in $data; blank text is stored as null. The
     * grant amount (funding_agency_amount) is stored as 0 when blank, which
     * means "not entered".
     */
    private function moaValues(array $data): array
    {
        $values = array_map(
            fn ($value) => ($value = trim((string) $value)) === '' ? null : $value,
            array_intersect_key($data, array_flip(Project::MOA_FIELDS))
        );
        if (array_key_exists('funding_agency_amount', $data)) {
            $values['funding_agency_amount'] = round((float) ($data['funding_agency_amount'] ?? 0), 2);
        }

        return $values;
    }

    /** The grant from the funder cannot be more than the project's total budget. */
    private function requireGrantWithinBudget(array $data, float $budget): void
    {
        $grant = round((float) ($data['funding_agency_amount'] ?? 0), 2);
        if ($grant > round($budget, 2)) {
            throw ValidationException::withMessages(['funding_agency_amount' => [
                'The grant amount (₱' . number_format($grant, 2) . ') cannot be more than the total budget (₱' . number_format($budget, 2) . ').',
            ]]);
        }
    }

    /** Funder, funder reference, period, total budget, Project Leader, the MOA fields and the printed Form B fields. */
    public function updateProject(Project $project, array $data, User $actor): Project
    {
        $leader = array_key_exists('project_leader_id', $data) ? $this->leaderOrFail($data['project_leader_id']) : null;

        return AuditLogger::transaction(function () use ($project, $data, $leader, $actor) {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $old = $this->projectSnapshot($locked);

            if (array_key_exists('total_approved_budget', $data)) {
                $allocated = (float) $locked->libCategories()->sum('allocated_amount');
                if ((float) $data['total_approved_budget'] + 0.001 < $allocated) {
                    throw ValidationException::withMessages(['total_approved_budget' => [
                        'The total budget cannot be less than the line items already allocated (₱' . number_format($allocated, 2) . ').',
                    ]]);
                }
            }

            if (array_key_exists('funding_agency_amount', $data) || array_key_exists('total_approved_budget', $data)) {
                $this->requireGrantWithinBudget(
                    ['funding_agency_amount' => array_key_exists('funding_agency_amount', $data) ? $data['funding_agency_amount'] : $locked->funding_agency_amount],
                    (float) ($data['total_approved_budget'] ?? $locked->total_approved_budget)
                );
            }

            $print = array_map(
                fn ($value) => ($value = trim((string) $value)) === '' ? null : $value,
                array_intersect_key($data, array_flip(Project::formBPrintFields()))
            );
            $locked->update(array_intersect_key($data, array_flip([
                'funding_agency_id', 'funder_reference', 'start_date', 'end_date', 'total_approved_budget',
            ])) + $print + $this->moaValues($data));
            if ($leader) {
                $this->setLeader($locked, $leader);
            }

            AuditLogger::write('updated', $locked, $old, $this->projectSnapshot($locked->fresh()), $actor);

            return $locked->fresh();
        });
    }

    // ---------------------------------------------------------------- line items

    public function addLineItem(Project $project, array $data, User $actor): LibCategory
    {
        return AuditLogger::transaction(function () use ($project, $data, $actor) {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $fields = $this->classification($data);
            $this->requireUniqueName($locked, $fields + ['line_item_name' => $data['line_item_name']]);
            $amounts = $this->amounts($data, null);
            if ($amounts === null) {
                throw ValidationException::withMessages(['funder_amount' => ['Enter the amounts or the computation.']]);
            }
            $this->requireWithinBudget($locked, $amounts['implementing_agency_amount'] + $amounts['funder_amount'], null, $amounts['key']);

            $item = LibCategory::create(array_merge($fields, $this->computation($data), [
                'project_id' => $locked->id,
                'line_item_name' => $data['line_item_name'],
                'implementing_agency_amount' => $amounts['implementing_agency_amount'],
                'funder_amount' => $amounts['funder_amount'],
            ]));
            AuditLogger::write('created', $item, [], $this->lineItemSnapshot($item), $actor);

            return $item->fresh();
        });
    }

    /** Category, cost type and groups, with their defaults. */
    private function classification(array $data, ?LibCategory $current = null): array
    {
        $pick = fn (string $key, $default = null) => array_key_exists($key, $data) ? $data[$key] : ($current ? $current->{$key} : $default);
        $text = fn ($value) => ($value = trim((string) $value)) === '' ? null : $value;

        return [
            'category_type' => $pick('category_type'),
            'cost_type' => $pick('cost_type') ?: 'Direct',
            'expense_group' => $text($pick('expense_group')) ?? trim((string) ($data['line_item_name'] ?? $current?->line_item_name)),
            'expense_subgroup' => $text($pick('expense_subgroup')),
        ];
    }

    /** The computation fields present in $data (all optional). */
    private function computation(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(LineItemComputation::FIELDS));
        if (array_key_exists('unit_description', $fields)) {
            $fields['unit_description'] = trim((string) $fields['unit_description']) ?: null;
        }

        return $fields;
    }

    /**
     * The two amounts after this change, or null when they do not change.
     *
     * - With a computation (quantity and rate), the funder share is the
     *   computed amount (as on Form B, where "One (1) Project Leader @
     *   ₱8,800.00/mo. x 12 mos." is the funder column); the counterpart is
     *   entered on its own (default 0) and added to it.
     * - Otherwise the entered implementing_agency_amount / funder_amount.
     * - Older callers that send only allocated_amount change the funder
     *   share (the counterpart stays).
     *
     * `key` is the field that error messages attach to.
     *
     * @return array{implementing_agency_amount: float, funder_amount: float, key: string}|null
     */
    private function amounts(array $data, ?LibCategory $current): ?array
    {
        $now = fn (string $column) => $current ? round((float) $current->{$column}, 2) : 0.0;
        $computationSent = array_intersect_key($data, array_flip(LineItemComputation::FIELDS)) !== [];
        $row = array_merge($current ? $current->only(LineItemComputation::FIELDS) : [], $this->computation($data));
        $sent = fn (string $key) => array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '';

        if (LineItemComputation::present($row) && ($computationSent || $sent('implementing_agency_amount') || $sent('funder_amount') || ! $current)) {
            $counterpart = $sent('implementing_agency_amount') ? round((float) $data['implementing_agency_amount'], 2) : $now('implementing_agency_amount');
            // Errors go to the counterpart field when only the counterpart was changed.
            $key = ! $computationSent && $sent('implementing_agency_amount') && ! $sent('funder_amount') ? 'implementing_agency_amount' : 'funder_amount';

            return ['implementing_agency_amount' => $counterpart, 'funder_amount' => LineItemComputation::amount($row), 'key' => $key];
        }
        if ($sent('implementing_agency_amount') || $sent('funder_amount')) {
            return [
                'implementing_agency_amount' => $sent('implementing_agency_amount') ? round((float) $data['implementing_agency_amount'], 2) : $now('implementing_agency_amount'),
                'funder_amount' => $sent('funder_amount') ? round((float) $data['funder_amount'], 2) : $now('funder_amount'),
                'key' => 'funder_amount',
            ];
        }
        if ($sent('allocated_amount')) {
            $counterpart = $now('implementing_agency_amount');
            $funder = round((float) $data['allocated_amount'] - $counterpart, 2);
            if ($funder < 0) {
                throw ValidationException::withMessages(['allocated_amount' => [
                    'The allocated amount cannot be less than the counterpart (₱' . number_format($counterpart, 2) . ').',
                ]]);
            }

            return ['implementing_agency_amount' => $counterpart, 'funder_amount' => $funder, 'key' => 'allocated_amount'];
        }

        return null;
    }

    public function updateLineItem(LibCategory $item, array $data, User $actor): LibCategory
    {
        return AuditLogger::transaction(function () use ($item, $data, $actor) {
            $project = Project::whereKey($item->project_id)->lockForUpdate()->firstOrFail();
            $locked = LibCategory::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $old = $this->lineItemSnapshot($locked);
            $used = $this->isUsed($locked);

            $type = $data['category_type'] ?? $locked->category_type;
            if ($type !== $locked->category_type && $used) {
                throw ValidationException::withMessages(['category_type' => ['This line item already has expenses, payroll or purchase requests; its category cannot change.']]);
            }
            $name = $data['line_item_name'] ?? $locked->line_item_name;
            $fields = $this->classification($data + ['line_item_name' => $name], $locked);
            $identity = fn (array $f) => mb_strtolower(implode('|', [$f['category_type'], $f['cost_type'], $f['expense_group'], $f['expense_subgroup'], $f['line_item_name']]));
            if ($identity($fields + ['line_item_name' => $name]) !== $identity($this->classification([], $locked) + ['line_item_name' => $locked->line_item_name])) {
                $this->requireUniqueName($project, $fields + ['line_item_name' => $name], $locked->id);
            }

            $changes = $fields + ['line_item_name' => $name] + $this->computation($data);
            $amounts = $this->amounts($data, $locked);
            $changed = $amounts !== null && (
                round($amounts['implementing_agency_amount'], 2) !== round((float) $locked->implementing_agency_amount, 2)
                || round($amounts['funder_amount'], 2) !== round((float) $locked->funder_amount, 2)
            );
            if ($changed) {
                $key = $amounts['key'];
                if ($this->hasVerifiedSpending($project)) {
                    throw ValidationException::withMessages([$key => [self::REALIGNMENT_ONLY]]);
                }
                // Each source keeps at least what is spent and pending from it.
                $row = $this->financials->balancing($project, $locked->id)['line_items'][0] ?? null;
                $labels = ProjectFinancialService::sourceLabels($project);
                foreach (LibCategory::SOURCE_COLUMN as $source => $column) {
                    $bucket = $row['sources'][$source] ?? ['spent' => 0, 'pending' => 0];
                    $floor = round((float) $bucket['spent'] + (float) $bucket['pending'], 2);
                    if ($amounts[$column] + 0.001 < $floor) {
                        $message = $key === 'allocated_amount'
                            ? 'The allocated amount cannot be less than what is already spent and pending on this line item (₱' . number_format($floor, 2) . ').'
                            : 'The ' . ($source === LibCategory::SOURCE_COUNTERPART ? $labels['implementing_agency'] . ' counterpart' : $labels['funder'] . ' amount')
                                . ' cannot be less than what is already spent and pending from it on this line item (₱' . number_format($floor, 2) . ').';
                        throw ValidationException::withMessages([$key === 'allocated_amount' ? $key : $column => [$message]]);
                    }
                }
                $this->requireWithinBudget($project, $amounts['implementing_agency_amount'] + $amounts['funder_amount'], $locked->id, $key);
            }
            if ($amounts !== null) {
                $changes['implementing_agency_amount'] = $amounts['implementing_agency_amount'];
                $changes['funder_amount'] = $amounts['funder_amount'];
            }

            $locked->update($changes);
            AuditLogger::write('updated', $locked, $old, $this->lineItemSnapshot($locked->fresh()), $actor);

            return $locked->fresh();
        });
    }

    public function removeLineItem(LibCategory $item, User $actor): void
    {
        AuditLogger::transaction(function () use ($item, $actor) {
            $locked = LibCategory::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($this->isUsed($locked)) {
                throw ValidationException::withMessages(['line_item' => ['This line item already has expenses, payroll or purchase requests, so it cannot be removed.']]);
            }
            $old = $this->lineItemSnapshot($locked);
            $locked->delete();
            AuditLogger::write('deleted', $locked, $old, [], $actor);
        });
    }

    // ---------------------------------------------------------------- tranches

    public function addTranche(Project $project, array $data, User $actor): ProjectTranche
    {
        return AuditLogger::transaction(function () use ($project, $data, $actor) {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->requireTranchesWithinBudget($locked, (float) $data['amount']);
            $sequence = (int) $locked->tranches()->max('sequence') + 1;

            $tranche = ProjectTranche::create([
                'project_id' => $locked->id,
                'sequence' => $sequence,
                // The number is assigned by the model (release_number); the name is only an optional milestone.
                'name' => trim((string) ($data['name'] ?? '')),
                'amount' => $data['amount'],
                'planned_release_date' => $data['planned_release_date'],
                'status' => 'upcoming',
            ]);
            AuditLogger::write('created', $tranche, [], $this->trancheSnapshot($tranche), $actor);

            return $tranche->fresh();
        });
    }

    /**
     * A project with no releases yet: Release 1 and Release 2 as 70% and
     * 30% of the funder total (centavos, the last takes the remainder).
     * Release 1 is planned on the project start date, Release 2 halfway
     * through the period; the Program Leader edits them as usual.
     *
     * @return array<int, ProjectTranche>
     */
    public function splitTranches(Project $project, User $actor, array $percents = [70, 30]): array
    {
        return AuditLogger::transaction(function () use ($project, $actor, $percents) {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($locked->tranches()->exists()) {
                throw ValidationException::withMessages(['tranche' => ['This project already has releases. Edit them instead.']]);
            }
            $total = $this->financials->funderTotal($locked);
            if ($total <= 0) {
                throw ValidationException::withMessages(['tranche' => ["Set the project's total budget first (Edit details)."]]);
            }

            $start = $locked->start_date?->copy() ?? now()->startOfDay();
            $end = $locked->end_date && $locked->end_date->gt($start) ? $locked->end_date : null;
            $dates = [$start, $end ? $start->copy()->addDays(intdiv($start->diffInDays($end), 2)) : $start->copy()->addMonths(6)];
            $milestones = ['Upon signing of the MOA', 'Upon liquidation of Release 1'];

            $created = [];
            foreach (self::splitAmounts($total, $percents) as $i => $amount) {
                $tranche = ProjectTranche::create([
                    'project_id' => $locked->id,
                    'sequence' => $i + 1,
                    'name' => $milestones[$i] ?? '',
                    'amount' => $amount,
                    'planned_release_date' => ($dates[$i] ?? end($dates))->toDateString(),
                    'status' => 'upcoming',
                ]);
                AuditLogger::write('created', $tranche, [], $this->trancheSnapshot($tranche), $actor);
                $created[] = $tranche->fresh();
            }

            return $created;
        });
    }

    public function updateTranche(ProjectTranche $tranche, array $data, User $actor): ProjectTranche
    {
        return AuditLogger::transaction(function () use ($tranche, $data, $actor) {
            $project = Project::whereKey($tranche->project_id)->lockForUpdate()->firstOrFail();
            $locked = ProjectTranche::whereKey($tranche->id)->lockForUpdate()->firstOrFail();
            $this->requireOpenTranche($locked, 'changed');
            if (array_key_exists('amount', $data)) {
                $this->requireTranchesWithinBudget($project, (float) $data['amount'], $locked->id);
            }

            $old = $this->trancheSnapshot($locked);
            $changes = array_intersect_key($data, array_flip(['name', 'amount', 'planned_release_date']));
            // The name is only an optional milestone (users read "Release N"); the
            // column needs a value, so a cleared one is stored empty. The
            // release number never changes here.
            if (array_key_exists('name', $changes)) {
                $changes['name'] = trim((string) $changes['name']);
            }
            $locked->update($changes);
            AuditLogger::write('updated', $locked, $old, $this->trancheSnapshot($locked->fresh()), $actor);

            return $locked->fresh();
        });
    }

    public function removeTranche(ProjectTranche $tranche, User $actor): void
    {
        AuditLogger::transaction(function () use ($tranche, $actor) {
            $locked = ProjectTranche::whereKey($tranche->id)->lockForUpdate()->firstOrFail();
            $this->requireOpenTranche($locked, 'removed');
            if ($locked->approvalRequests()->exists()) {
                throw ValidationException::withMessages(['tranche' => ['This release has fund release requests on record, so it cannot be removed.']]);
            }
            $old = $this->trancheSnapshot($locked);
            $locked->delete();
            AuditLogger::write('deleted', $locked, $old, [], $actor);
        });
    }

    // ---------------------------------------------------------------- page state

    /**
     * What the project page needs to offer the right controls: current
     * values for the edit form, the budget against the line items, which
     * line items are in use, and which tranches can still change.
     */
    public function state(Project $project): array
    {
        $leader = $project->projectUsers()
            ->whereHas('role', fn ($q) => $q->where('slug', 'project-leader'))
            ->orderBy('id')->value('user_id');
        $usedIds = $project->libCategories()->get()->filter(fn (LibCategory $item) => $this->isUsed($item))->pluck('id')->values()->all();
        $pendingTranches = ApprovalRequest::query()
            ->where('project_id', $project->id)->where('type', 'disbursement')->where('status', 'pending')
            ->pluck('project_tranche_id')->filter()->map(fn ($id) => (int) $id)->all();
        $basis = $this->financials->funderTotalBasis($project);

        return [
            'funding_agency_id' => $project->funding_agency_id,
            'funder_reference' => $project->funder_reference,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'project_leader_id' => $leader !== null ? (int) $leader : null,
            // From the Memorandum of Agreement (optional), with the period in words.
            'agreement' => $project->agreement(),
            // Printed on R&D Form B (optional; blank Monitoring Agency = the funder's name).
            'form_b' => $project->only(Project::formBPrintFields()),
            'total_budget' => round((float) $project->total_approved_budget, 2),
            'allocated' => round((float) $project->libCategories()->sum('allocated_amount'), 2),
            // Funder and counterpart totals are the line-item sums.
            'funding_totals' => $this->financials->fundingTotals($project),
            'labels' => ProjectFinancialService::sourceLabels($project),
            'tranches_total' => round((float) $project->tranches()->sum('amount'), 2),
            // The funder's grant: the cap for planned releases and the base of their percent.
            'funder_total' => $basis['amount'],
            // moa | line_items | budget: where the funder total came from.
            'funder_total_source' => $basis['source'],
            // "Grant amount from the funder (per MOA)"; null when not entered.
            'funder_grant' => (float) $project->funding_agency_amount > 0 ? round((float) $project->funding_agency_amount, 2) : null,
            // After the first verified expense, amounts change only by realignment.
            'allocations_locked' => $this->hasVerifiedSpending($project),
            'used_line_items' => $usedIds,
            'editable_tranches' => $project->tranches()->where('status', '!=', 'released')->pluck('id')
                ->map(fn ($id) => (int) $id)->reject(fn ($id) => in_array($id, $pendingTranches, true))->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- options

    /** Funders and the active Project Leader accounts, for the forms. */
    public function options(): array
    {
        return [
            'funders' => \App\Models\FundingAgency::query()->orderBy('name')->get()
                ->map(fn ($agency) => ['id' => $agency->id, 'name' => $agency->name, 'acronym' => $agency->acronym])
                ->values()->all(),
            'leaders' => User::query()
                ->where('is_active', true)
                ->whereHas('primaryRole', fn ($role) => $role->where('slug', 'project-leader'))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function leaderOrFail($userId): User
    {
        $user = User::with('primaryRole')->find($userId);
        if (! $user || ! $user->is_active || $user->primaryRole?->slug !== 'project-leader') {
            throw ValidationException::withMessages(['project_leader_id' => ['Choose an active Project Leader account.']]);
        }

        return $user;
    }

    /**
     * Make $leader the project's only Project Leader: other people's leader
     * rows go (their other roles stay); the leader's own rows on the project
     * become one leader row (see User::rolesOn).
     */
    private function setLeader(Project $project, User $leader): void
    {
        $roleId = Role::where('slug', 'project-leader')->value('id');

        ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('role_id', $roleId)
            ->where('user_id', '!=', $leader->id)
            ->get()
            ->each(fn (ProjectUser $row) => $row->delete());

        $own = ProjectUser::query()->where('project_id', $project->id)->where('user_id', $leader->id)->orderBy('id')->get();
        if ($own->isEmpty()) {
            ProjectUser::create(['project_id' => $project->id, 'user_id' => $leader->id, 'role_id' => $roleId, 'assigned_at' => now()]);

            return;
        }
        $own->slice(1)->each(fn (ProjectUser $row) => $row->delete());
        if ((int) $own->first()->role_id !== (int) $roleId) {
            $own->first()->update(['role_id' => $roleId]);
        }
    }

    private function requireWithinBudget(Project $project, float $amount, ?int $exceptId = null, string $key = 'allocated_amount'): void
    {
        $budget = round((float) $project->total_approved_budget, 2);
        if ($budget <= 0) {
            throw ValidationException::withMessages([$key => ["Set the project's total budget first."]]);
        }
        $others = (float) $project->libCategories()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('allocated_amount');
        $total = round($others + $amount, 2);
        if ($total > $budget) {
            throw ValidationException::withMessages([$key => [
                'Line items would total ₱' . number_format($total, 2) . ', more than the total budget of ₱' . number_format($budget, 2)
                . '. ₱' . number_format(max(0, $budget - $others), 2) . ' is left to allocate.',
            ]]);
        }
    }

    /** Planned releases may not exceed the funder total (ProjectFinancialService::funderTotal). */
    private function requireTranchesWithinBudget(Project $project, float $amount, ?int $exceptId = null): void
    {
        $cap = $this->financials->funderTotal($project);
        $others = (float) $project->tranches()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('amount');
        if ($cap > 0 && round($others + $amount, 2) > $cap) {
            throw ValidationException::withMessages(['amount' => [
                'Releases would total ₱' . number_format($others + $amount, 2) . ', more than the funder total of ₱' . number_format($cap, 2) . '.',
            ]]);
        }
    }

    /**
     * Shares of $total in whole centavos, in order; the last share takes the
     * remainder so the amounts add up to $total exactly.
     *
     * @param  array<int, float>  $percents
     * @return array<int, float>
     */
    public static function splitAmounts(float $total, array $percents): array
    {
        $cents = (int) round($total * 100);
        $amounts = [];
        $given = 0;
        foreach (array_values($percents) as $i => $percent) {
            $share = $i === count($percents) - 1 ? $cents - $given : (int) round($cents * $percent / 100);
            $given += $share;
            $amounts[] = round($share / 100, 2);
        }

        return $amounts;
    }

    /** One name per place: category, cost type, group and sub-group. */
    private function requireUniqueName(Project $project, array $fields, ?int $exceptId = null): void
    {
        $lower = fn ($value) => mb_strtolower(trim((string) $value));
        $exists = $project->libCategories()
            ->where('category_type', $fields['category_type'])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get()
            ->contains(fn (LibCategory $item) => $lower($item->line_item_name) === $lower($fields['line_item_name'])
                && ($item->cost_type ?: 'Direct') === $fields['cost_type']
                && $lower($item->expense_group ?: $item->line_item_name) === $lower($fields['expense_group'])
                && $lower($item->expense_subgroup) === $lower($fields['expense_subgroup']));
        if ($exists) {
            throw ValidationException::withMessages(['line_item_name' => ["This project already has a {$fields['category_type']} line item with that name in this group."]]);
        }
    }

    private function requireOpenTranche(ProjectTranche $tranche, string $verb): void
    {
        if ($tranche->isReleased()) {
            throw ValidationException::withMessages(['tranche' => ["The funds for this release are already released, so it cannot be {$verb}."]]);
        }
        $pending = ApprovalRequest::query()
            ->where('project_tranche_id', $tranche->id)->where('type', 'disbursement')->where('status', 'pending')
            ->exists();
        if ($pending) {
            throw ValidationException::withMessages(['tranche' => ["A fund release request for this release is pending, so it cannot be {$verb}."]]);
        }
    }

    /** Any expense (any status, deleted drafts included), payroll row or purchase request. */
    private function isUsed(LibCategory $item): bool
    {
        return $item->expenses()->withTrashed()->exists()
            || $item->payrolls()->exists()
            || $item->purchaseRequests()->exists();
    }

    private function hasVerifiedSpending(Project $project): bool
    {
        return ProjectFinancialService::onlySpent($project->expenses())->exists()
            || ProjectFinancialService::onlySpentPayroll($project->payrolls())->exists();
    }

    private function projectSnapshot(Project $project): array
    {
        $leader = $project->projectUsers()
            ->whereHas('role', fn ($q) => $q->where('slug', 'project-leader'))
            ->with('user')->orderBy('id')->first();

        return [
            'title' => $project->title,
            'code' => $project->code,
            'funding_agency_id' => $project->funding_agency_id,
            'funder_reference' => $project->funder_reference,
            'total_approved_budget' => round((float) $project->total_approved_budget, 2),
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'project_leader_id' => $leader ? (int) $leader->user_id : null,
            'project_leader' => $leader?->user?->name,
            'moa_number' => $project->moa_number,
            'moa_date' => $project->moa_date?->toDateString(),
            'rd_committee_approved_date' => $project->rd_committee_approved_date?->toDateString(),
            'fund_source' => $project->fund_source,
            'funding_agency_amount' => round((float) $project->funding_agency_amount, 2),
        ] + $project->only(Project::formBPrintFields());
    }

    private function lineItemSnapshot(LibCategory $item): array
    {
        return [
            'project_id' => $item->project_id,
            'category_type' => $item->category_type,
            'cost_type' => $item->cost_type,
            'expense_group' => $item->expense_group,
            'expense_subgroup' => $item->expense_subgroup,
            'line_item_name' => $item->line_item_name,
            'computation' => $item->computationText(),
            'implementing_agency_amount' => round((float) $item->implementing_agency_amount, 2),
            'funder_amount' => round((float) $item->funder_amount, 2),
            'allocated_amount' => round((float) $item->allocated_amount, 2),
        ];
    }

    private function trancheSnapshot(ProjectTranche $tranche): array
    {
        return [
            'project_id' => (int) $tranche->project_id,
            'sequence' => (int) $tranche->sequence,
            'release_number' => $tranche->release_number,
            'name' => $tranche->name,
            'amount' => round((float) $tranche->amount, 2),
            'planned_release_date' => $tranche->planned_release_date?->toDateString(),
        ];
    }
}

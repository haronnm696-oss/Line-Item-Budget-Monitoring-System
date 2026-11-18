<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
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
    public const REALIGNMENT_ONLY = 'This project already has verified expenses. Change allocated amounts through a realignment request.';

    public function __construct(private ProjectFinancialService $financials)
    {
    }

    // ---------------------------------------------------------------- project

    public function createProject(array $data, User $actor): Project
    {
        $leader = $this->leaderOrFail($data['project_leader_id']);

        return AuditLogger::transaction(function () use ($data, $leader, $actor) {
            $project = Project::create([
                'title' => $data['title'],
                'code' => $data['code'],
                'funding_agency_id' => $data['funding_agency_id'],
                'funder_reference' => $data['funder_reference'] ?? null,
                'total_approved_budget' => $data['total_approved_budget'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
            ]);
            $this->setLeader($project, $leader);

            AuditLogger::write('created', $project, [], $this->projectSnapshot($project->fresh()), $actor);

            return $project->fresh();
        });
    }

    /** Funder, funder reference, period, total budget and Project Leader. */
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

            $locked->update(array_intersect_key($data, array_flip([
                'funding_agency_id', 'funder_reference', 'start_date', 'end_date', 'total_approved_budget',
            ])));
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
            $this->requireUniqueName($locked, $data['category_type'], $data['line_item_name']);
            $this->requireWithinBudget($locked, (float) $data['allocated_amount']);

            $item = LibCategory::create([
                'project_id' => $locked->id,
                'category_type' => $data['category_type'],
                'line_item_name' => $data['line_item_name'],
                'allocated_amount' => $data['allocated_amount'],
            ]);
            AuditLogger::write('created', $item, [], $this->lineItemSnapshot($item), $actor);

            return $item->fresh();
        });
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
            if ($type !== $locked->category_type || $name !== $locked->line_item_name) {
                $this->requireUniqueName($project, $type, $name, $locked->id);
            }

            if (array_key_exists('allocated_amount', $data) && round((float) $data['allocated_amount'], 2) !== round((float) $locked->allocated_amount, 2)) {
                $amount = (float) $data['allocated_amount'];
                if ($this->hasVerifiedSpending($project)) {
                    throw ValidationException::withMessages(['allocated_amount' => [self::REALIGNMENT_ONLY]]);
                }
                $row = $this->financials->balancing($project, $locked->id)['line_items'][0] ?? ['spent' => 0, 'pending' => 0];
                $floor = round((float) $row['spent'] + (float) $row['pending'], 2);
                if ($amount + 0.001 < $floor) {
                    throw ValidationException::withMessages(['allocated_amount' => [
                        'The allocated amount cannot be less than what is already spent and pending on this line item (₱' . number_format($floor, 2) . ').',
                    ]]);
                }
                $this->requireWithinBudget($project, $amount, $locked->id);
            }

            $locked->update(array_intersect_key($data, array_flip(['category_type', 'line_item_name', 'allocated_amount'])));
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

        return [
            'funding_agency_id' => $project->funding_agency_id,
            'funder_reference' => $project->funder_reference,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'project_leader_id' => $leader !== null ? (int) $leader : null,
            'total_budget' => round((float) $project->total_approved_budget, 2),
            'allocated' => round((float) $project->libCategories()->sum('allocated_amount'), 2),
            'tranches_total' => round((float) $project->tranches()->sum('amount'), 2),
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

    private function requireWithinBudget(Project $project, float $amount, ?int $exceptId = null): void
    {
        $budget = round((float) $project->total_approved_budget, 2);
        if ($budget <= 0) {
            throw ValidationException::withMessages(['allocated_amount' => ["Set the project's total budget first."]]);
        }
        $others = (float) $project->libCategories()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('allocated_amount');
        $total = round($others + $amount, 2);
        if ($total > $budget) {
            throw ValidationException::withMessages(['allocated_amount' => [
                'Line items would total ₱' . number_format($total, 2) . ', more than the total budget of ₱' . number_format($budget, 2)
                . '. ₱' . number_format(max(0, $budget - $others), 2) . ' is left to allocate.',
            ]]);
        }
    }

    private function requireTranchesWithinBudget(Project $project, float $amount, ?int $exceptId = null): void
    {
        $budget = round((float) $project->total_approved_budget, 2);
        $others = (float) $project->tranches()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('amount');
        if ($budget > 0 && round($others + $amount, 2) > $budget) {
            throw ValidationException::withMessages(['amount' => [
                'Releases would total ₱' . number_format($others + $amount, 2) . ', more than the total budget of ₱' . number_format($budget, 2) . '.',
            ]]);
        }
    }

    private function requireUniqueName(Project $project, string $type, string $name, ?int $exceptId = null): void
    {
        $exists = $project->libCategories()
            ->where('category_type', $type)
            ->whereRaw('LOWER(line_item_name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['line_item_name' => ["This project already has a {$type} line item with that name."]]);
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
        return ProjectFinancialService::onlySpent($project->expenses())->exists();
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
        ];
    }

    private function lineItemSnapshot(LibCategory $item): array
    {
        return [
            'project_id' => $item->project_id,
            'category_type' => $item->category_type,
            'line_item_name' => $item->line_item_name,
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

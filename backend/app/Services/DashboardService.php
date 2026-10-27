<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use Illuminate\Http\Request;

class DashboardService
{
    /**
     * Resolve the project a dashboard request refers to. Program leaders see
     * the current ongoing project by default; an explicit project_id wins.
     */
    public function resolveProject(Request $request): Project
    {
        $query = Project::query()->with('fundingAgency');

        if ($request->filled('project_id')) {
            $query->whereKey((int) $request->query('project_id'));
        } else {
            $query->where('status', 'ongoing')->orderBy('id');
        }

        $project = $query->first() ?? Project::query()->orderBy('id')->first();

        abort_unless($project, 404, 'No project found.');

        return $project;
    }

    /**
     * Full payload consumed by the Program Leader dashboard.
     */
    public function build(Project $project): array
    {
        $project->loadMissing(['fundingAgency', 'libCategories']);

        $categories = $this->categoryRows($project);
        $ps = array_values(array_filter($categories, fn ($c) => $c['type'] === 'PS'));
        $mooe = array_values(array_filter($categories, fn ($c) => $c['type'] === 'MOOE'));

        $totalAllocated = (float) array_sum(array_column($categories, 'allocated'));
        $totalSpent = (float) array_sum(array_column($categories, 'spent'));
        $budget = $totalAllocated > 0 ? $totalAllocated : (float) $project->total_approved_budget;
        $remaining = $budget - $totalSpent;

        $tranches = $this->trancheRows($project);
        $approvals = $this->pendingApprovalRows($project);
        $compliance = $this->compliance($project);
        $burnRate = $this->burnRate($project, $budget, $totalSpent);

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'title' => $project->title,
                'description' => $project->description,
                'implementing_agency' => 'MSU-Marawi',
                'funding_agency' => $this->fundingAgencyName($project),
                'project_leader' => $this->projectLeaderName($project) ?? 'Unassigned',
                'period' => $this->period($project),
                'status' => ucfirst($project->status),
                'status_key' => $project->status,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'total_budget' => $budget,
                'total_spent' => $totalSpent,
                'remaining' => $remaining,
                'utilization_pct' => $this->pct($totalSpent, $budget),
                'counterpart_budget' => (float) $project->implementing_agency_counterpart,
                'funding_budget' => (float) $project->funding_agency_amount,
            ],
            'kpis' => [
                'total_budget' => $budget,
                'total_spent' => $totalSpent,
                'remaining' => $remaining,
                'utilization_pct' => $this->pct($totalSpent, $budget),
                'pending_approvals' => count($approvals),
                'tranches_released' => $tranches['summary']['released_count'],
                'tranches_total' => $tranches['summary']['total_count'],
            ],
            'categories' => [
                'ps' => $ps,
                'mooe' => $mooe,
            ],
            'tranches' => $tranches,
            'burn_rate' => $burnRate,
            'approvals' => $approvals,
            'compliance' => $compliance,
            'funding_sources' => [
                'counterpart' => (float) $project->implementing_agency_counterpart,
                'funding' => (float) $project->funding_agency_amount,
                'total' => (float) $project->total_approved_budget,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categoryRows(Project $project): array
    {
        $project->loadMissing('libCategories');

        return $project->libCategories
            ->map(function (LibCategory $category) {
                $allocated = (float) $category->allocated_amount;
                $spent = $category->spentAmount();

                return [
                    'id' => $category->id,
                    'type' => $category->category_type,
                    'name' => $category->line_item_name,
                    'code' => $category->line_item_code,
                    'allocated' => $allocated,
                    'spent' => $spent,
                    'remaining' => $allocated - $spent,
                    'utilization_pct' => $this->pct($spent, $allocated),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function trancheRows(Project $project): array
    {
        $project->loadMissing('tranches');
        $today = now()->startOfDay();

        $items = $project->tranches
            ->map(function (ProjectTranche $tranche) use ($today) {
                $displayStatus = $tranche->status;
                if ($tranche->status === 'upcoming'
                    && $tranche->planned_release_date
                    && $tranche->planned_release_date->lt($today)) {
                    $displayStatus = 'due';
                }

                return [
                    'id' => $tranche->id,
                    'sequence' => $tranche->sequence,
                    'name' => $tranche->name,
                    'amount' => (float) $tranche->amount,
                    'percentage' => $tranche->percentage !== null ? (float) $tranche->percentage : null,
                    'status' => $tranche->status,
                    'display_status' => $displayStatus,
                    'planned_release_date' => $tranche->planned_release_date?->toDateString(),
                    'released_at' => $tranche->released_at?->toDateString(),
                    'notes' => $tranche->notes,
                    'is_released' => $tranche->status === 'released',
                ];
            })
            ->values()
            ->all();

        $released = collect($items)->where('is_released', true);
        $totalAmount = (float) collect($items)->sum('amount');
        $releasedAmount = (float) $released->sum('amount');

        return [
            'items' => $items,
            'summary' => [
                'total_count' => count($items),
                'released_count' => $released->count(),
                'released_amount' => $releasedAmount,
                'upcoming_amount' => max(0, $totalAmount - $releasedAmount),
                'total_amount' => $totalAmount,
                'progress_pct' => $this->pct($releasedAmount, $totalAmount),
            ],
        ];
    }

    /**
     * Burn rate compares elapsed project time against budget consumption and
     * projects the final spend at the current monthly run rate.
     */
    public function burnRate(Project $project, ?float $budget = null, ?float $spent = null): array
    {
        $budget ??= (float) $project->total_approved_budget;
        $spent ??= $project->totalSpent();

        $start = $project->start_date
            ? $project->start_date->copy()->startOfDay()
            : now()->startOfDay();
        $end = $project->end_date
            ? $project->end_date->copy()->startOfDay()
            : $start->copy()->addYear();
        $today = now()->startOfDay();

        $totalDays = max(1, (int) $start->diffInDays($end));
        $elapsedDays = 0;
        if ($today->greaterThan($start)) {
            $elapsedDays = min($totalDays, (int) $start->diffInDays($today));
        }

        $elapsedPct = round(min(100, ($elapsedDays / $totalDays) * 100), 1);
        $spentPct = $this->pct($spent, $budget);
        $variance = round($spentPct - $elapsedPct, 1);

        $monthsElapsed = max(0.1, $elapsedDays / 30.44);
        $totalMonths = max(0.1, $totalDays / 30.44);
        $monthlyBurn = $elapsedDays > 0 ? round($spent / $monthsElapsed, 2) : 0.0;
        $projectedTotal = round($monthlyBurn * $totalMonths, 2);

        [$status, $label, $tone, $message] = $this->burnStatus(
            $start,
            $today,
            $budget,
            $spent,
            $variance,
            $projectedTotal
        );

        return [
            'status' => $status,
            'status_label' => $label,
            'tone' => $tone,
            'message' => $message,
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $budget - $spent,
            'elapsed_pct' => $elapsedPct,
            'spent_pct' => $spentPct,
            'variance_pct' => $variance,
            'elapsed_days' => $elapsedDays,
            'total_days' => $totalDays,
            'days_remaining' => $today->greaterThan($end) ? 0 : (int) $today->diffInDays($end),
            'months_elapsed' => round($monthsElapsed, 1),
            'monthly_burn' => $monthlyBurn,
            'projected_total' => $projectedTotal,
            'forecast_variance' => round($projectedTotal - $budget, 2),
        ];
    }

    /**
     * Receipt/document compliance across all expenses of a project.
     */
    public function compliance(Project $project): array
    {
        $expenses = $project->expenses()
            ->with(['libCategory', 'verifiedBy'])
            ->get();

        $total = $expenses->count();
        $withReceipt = $expenses->filter(fn ($e) => filled($e->attachment_path))->count();
        $verified = $expenses->where('status', 'verified')->count();
        $pending = $expenses->where('status', 'pending')->count();
        $rejected = $expenses->where('status', 'rejected')->count();
        $verifiedWithReceipt = $expenses
            ->filter(fn ($e) => $e->status === 'verified' && filled($e->attachment_path))
            ->count();

        $compliancePct = $this->pct($withReceipt, $total);
        $verifiedPct = $this->pct($verifiedWithReceipt, $total);
        $missing = $expenses
            ->filter(fn ($e) => blank($e->attachment_path))
            ->sortByDesc(fn ($e) => $e->expense_date?->timestamp ?? 0)
            ->values();

        return [
            'total_expenses' => $total,
            'with_receipt' => $withReceipt,
            'missing_receipt' => $missing->count(),
            'verified' => $verified,
            'pending' => $pending,
            'rejected' => $rejected,
            'verified_with_receipt' => $verifiedWithReceipt,
            'compliance_pct' => $compliancePct,
            'verified_pct' => $verifiedPct,
            'status' => $this->complianceLabel($total, $compliancePct),
            'tone' => $this->complianceTone($total, $compliancePct),
            'missing_items' => $missing->take(6)->map(fn ($e) => [
                'id' => $e->id,
                'reference_no' => $e->reference_no,
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'expense_date' => $e->expense_date?->toDateString(),
                'status' => ucfirst($e->status),
                'category' => $e->libCategory?->line_item_name,
            ])->all(),
        ];
    }

    /**
     * Pending approval rows for the dashboard widget.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingApprovalRows(Project $project): array
    {
        return ApprovalRequest::query()
            ->with(['requestedBy', 'tranche'])
            ->where('project_id', $project->id)
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(fn (ApprovalRequest $approval) => $this->approvalRow($approval))
            ->all();
    }

    /**
     * Serialize one approval request for the API/frontend.
     *
     * @return array<string, mixed>
     */
    public function approvalRow(ApprovalRequest $approval): array
    {
        $approval->loadMissing(['requestedBy', 'tranche']);

        return [
            'id' => $approval->id,
            'project_id' => $approval->project_id,
            'project_tranche_id' => $approval->project_tranche_id,
            'tranche_name' => $approval->tranche?->name,
            'type' => $approval->type,
            'type_label' => $approval->typeLabel(),
            'title' => $approval->title,
            'description' => $approval->description,
            'amount' => $approval->amount !== null ? (float) $approval->amount : null,
            'status' => $approval->status,
            'payload' => $approval->payload,
            'requested_by' => $approval->requestedBy?->name,
            'requested_by_id' => $approval->requested_by,
            'review_notes' => $approval->review_notes,
            'created_at' => $approval->created_at?->toIso8601String(),
            'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
        ];
    }

    protected function burnStatus(
        $start,
        $today,
        float $budget,
        float $spent,
        float $variance,
        float $projectedTotal
    ): array {
        if ($start->isFuture()) {
            return [
                'not_started',
                'Not Started',
                'neutral',
                'The project has not started yet; burn rate will appear once implementation begins.',
            ];
        }

        if ($budget > 0 && ($spent > $budget || $projectedTotal > $budget * 1.05 || $variance > 15)) {
            $overrun = $projectedTotal - $budget;
            $message = $overrun > 0
                ? 'At the current run rate the project is projected to exceed its budget by '
                    . number_format($overrun, 2) . '.'
                : 'Spending is running materially ahead of the elapsed project timeline.';

            return ['high_risk', 'High Burn Rate Risk', 'over', $message];
        }

        if ($variance < -15) {
            return [
                'under_spending',
                'Under-spending',
                'warn',
                'Budget consumption is behind the elapsed project timeline; review work plan acceleration.',
            ];
        }

        return [
            'on_track',
            'On Track',
            'ok',
            'Budget consumption is broadly aligned with the elapsed project timeline.',
        ];
    }

    protected function complianceLabel(int $total, float $pct): string
    {
        if ($total === 0) {
            return 'No expenses yet';
        }

        if ($pct >= 90) {
            return 'Audit Ready';
        }

        if ($pct >= 70) {
            return 'Needs Attention';
        }

        return 'High Compliance Risk';
    }

    protected function complianceTone(int $total, float $pct): string
    {
        if ($total === 0) {
            return 'neutral';
        }

        if ($pct >= 90) {
            return 'ok';
        }

        return $pct >= 70 ? 'warn' : 'over';
    }

    protected function fundingAgencyName(Project $project): string
    {
        if ($project->fundingAgency) {
            return $project->fundingAgency->acronym ?: $project->fundingAgency->name;
        }

        return $project->code === 'MOST-RD-2026-001' ? 'MOST-BARMM' : 'External Funding Agency';
    }

    protected function projectLeaderName(Project $project): ?string
    {
        $leader = $project->usersWithRole('project-leader')->first();
        if ($leader) {
            return $leader->name;
        }

        return $project->users()
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'project-leader'))
            ->first()?->name;
    }

    protected function period(Project $project): string
    {
        $start = $project->start_date?->format('M Y');
        $end = $project->end_date?->format('M Y');

        return $start && $end ? "{$start} – {$end}" : '—';
    }

    protected function pct(float $part, float $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}

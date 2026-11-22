<?php

namespace App\Services;

use App\Support\Percent;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Support\ReleaseLiquidation;
use Illuminate\Http\Request;

class DashboardService
{
    public function __construct(private ProjectFinancialService $financials)
    {
    }

    /**
     * Resolve the project a dashboard request refers to. Program leaders see
     * the current ongoing project by default; an explicit project_id wins.
     */
    public function resolveProject(Request $request): Project
    {
        $projectIds = $request->user()?->accessibleProjectIds();

        if ($request->filled('project_id') && ! $request->user()?->canAccessProject((int) $request->query('project_id'))) {
            abort(403, 'You are not assigned to this project.');
        }

        $query = Project::query()
            ->with('fundingAgency')
            ->when($projectIds !== null, fn ($q) => $q->whereIn('id', $projectIds));

        if ($request->filled('project_id')) {
            $query->whereKey((int) $request->query('project_id'));
        } else {
            $query->where('status', 'ongoing')->orderBy('id');
        }

        $project = $query->first() ?? Project::query()
            ->when($projectIds !== null, fn ($q) => $q->whereIn('id', $projectIds))
            ->orderBy('id')
            ->first();

        abort_unless($project, 404, 'No project found.');

        return $project;
    }

    /**
     * Project list rows for the Projects page. Uses the same canonical
     * ProjectFinancialService as every dashboard and report.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<int, int>|null $projectIds limit to these projects (null = all)
     */
    public function projectSummaries(?array $projectIds = null): array
    {
        return Project::query()
            ->with('fundingAgency')
            ->when($projectIds !== null, fn ($query) => $query->whereIn('id', $projectIds))
            ->orderBy('code')
            ->get()
            ->map(function (Project $project) {
                $metrics = $this->financials->financials($project);
                $status = $this->financials->derivedStatus($project);

                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                    'description' => $project->description,
                    'agency' => $project->fundingAgency
                        ? ($project->fundingAgency->acronym ?: $project->fundingAgency->name)
                        : 'Unassigned funder',
                    'funding_agency_id' => $project->funding_agency_id,
                    'budget' => $metrics['budget'],
                    'spent' => $metrics['spent'],
                    'remaining' => $metrics['remaining'],
                    'utilization_pct' => $metrics['utilization_pct'],
                    'period' => $this->period($project),
                    'status' => ucfirst($status),
                    'status_key' => $status,
                    'start_date' => $project->start_date?->toDateString(),
                    'end_date' => $project->end_date?->toDateString(),
                    'program' => $this->programLabel($project),
                    'expenses_count' => $metrics['expenses_count'],
                    'verified_count' => $metrics['verified_count'],
                    'receipts_count' => $metrics['receipts_count'],
                    'receipt_compliance_pct' => $metrics['receipt_compliance_pct'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Full payload consumed by the Program Leader dashboard.
     */
    public function build(Project $project): array
    {
        $project->loadMissing(['fundingAgency', 'libCategories']);

        $metrics = $this->financials->financials($project);
        $categories = $this->financials->categoryRows($project);
        $ps = array_values(array_filter($categories, fn ($c) => $c['type'] === 'PS'));
        $mooe = array_values(array_filter($categories, fn ($c) => $c['type'] === 'MOOE'));

        $budget = $metrics['budget'];
        $totalSpent = $metrics['spent'];
        $remaining = $metrics['remaining'];
        $status = $this->financials->derivedStatus($project);

        $tranches = $this->trancheRows($project);
        $approvals = $this->pendingApprovalRows($project);
        $compliance = $this->financials->compliance($project);
        $funding = $this->financials->fundingTotals($project);
        $burnRate = $this->burnRate($project, $budget, $totalSpent);

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'title' => $project->title,
                'description' => $project->description,
                'implementing_agency' => ProjectFinancialService::implementingAgencyName($project),
                'funding_agency' => $this->fundingAgencyName($project),
                // Why "Export Form B" is disabled (no funder / no line items), else null.
                'form_b_blocker' => app(FormBReportService::class)->blocker($project),
                'project_leader' => $this->projectLeaderName($project) ?? 'Unassigned',
                'period' => $this->period($project),
                'status' => ucfirst($status),
                'status_key' => $status,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'total_budget' => $budget,
                'total_spent' => $totalSpent,
                'remaining' => $remaining,
                'utilization_pct' => $metrics['utilization_pct'],
                // Sums of the line items (no separately typed figure).
                'counterpart_budget' => $funding['implementing_agency'],
                'funding_budget' => $funding['funder'],
                'funder_label' => ProjectFinancialService::sourceLabels($project)['funder'],
                // From the Memorandum of Agreement (optional), with the period in words.
                'agreement' => $project->agreement(),
            ],
            'kpis' => [
                'total_budget' => $budget,
                'total_spent' => $totalSpent,
                'remaining' => $remaining,
                'utilization_pct' => $metrics['utilization_pct'],
                'pending_approvals' => count($approvals),
                'tranches_released' => $tranches['summary']['released_count'],
                'tranches_total' => $tranches['summary']['total_count'],
                'expenses_count' => $metrics['expenses_count'],
                'verified_count' => $metrics['verified_count'],
                'receipts_count' => $metrics['receipts_count'],
                'receipt_compliance_pct' => $metrics['receipt_compliance_pct'],
            ],
            'categories' => [
                'ps' => $ps,
                'mooe' => $mooe,
            ],
            'expenses' => $this->expenseRows($project),
            'tranches' => $tranches,
            'burn_rate' => $burnRate,
            'approvals' => $approvals,
            'compliance' => $compliance,
            'funding_sources' => [
                'counterpart' => $funding['implementing_agency'],
                'funding' => $funding['funder'],
                'total' => $budget,
                'labels' => ProjectFinancialService::sourceLabels($project),
            ],
            'form_b' => $this->financials->formB($project),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categoryRows(Project $project): array
    {
        return $this->financials->categoryRows($project);
    }

    /**
     * Expense rows used by the Reports page previews.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * The project's ledger: every expense (any status) plus verified payroll
     * (one row per batch and line item, `kind` payroll), newest first.
     */
    protected function expenseRows(Project $project): array
    {
        $payroll = ProjectFinancialService::payrollEntries([(int) $project->id])->map(fn (array $p) => [
            'kind' => 'payroll',
            'id' => $p['key'],
            'batch_id' => $p['batch_id'],
            'date' => $p['date'],
            'ref' => $p['reference_no'],
            'desc' => $p['description'],
            'category' => $p['line_item'],
            'type' => $p['type'],
            'amount' => $p['amount'],
            'status' => 'Verified',
            'recorded_by' => $p['recorded_by'],
            'verified_by' => $p['verified_by'],
        ]);

        return $project->expenses()
            ->with(['libCategory', 'recordedBy', 'verifiedBy'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($expense) => [
                'kind' => 'expense',
                'id' => $expense->id,
                'date' => $expense->expense_date?->toDateString(),
                'ref' => $expense->reference_no,
                'desc' => $expense->description,
                'category' => $expense->libCategory?->line_item_name,
                'type' => $expense->libCategory?->category_type,
                'amount' => (float) $expense->amount,
                'status' => ucfirst($expense->status),
                'recorded_by' => $expense->recordedBy?->name,
                'verified_by' => $expense->verifiedBy?->name,
            ])
            ->concat($payroll)
            ->sortByDesc('date')
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

        // Listed by planned date; each keeps its stored number.
        $items = ProjectTranche::sortByPlannedDate($project->tranches)
            ->map(function (ProjectTranche $tranche) use ($today) {
                $displayStatus = $tranche->status;
                if ($tranche->status !== 'released'
                    && $tranche->planned_release_date
                    && $tranche->planned_release_date->lt($today)) {
                    $displayStatus = 'overdue';
                }

                return [
                    'id' => $tranche->id,
                    'sequence' => $tranche->sequence,
                    'release_number' => $tranche->release_number,
                    // "Release 2", what users read; the stored name only adds a milestone.
                    'name' => $tranche->releaseLabel(),
                    'milestone' => $tranche->releaseMilestone(),
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
                // Shown on the fund release request form (a warning, not a block).
                'liquidation_warning' => ReleaseLiquidation::warning($project),
            ],
        ];
    }

    /**
     * Burn rate compares elapsed project time against budget consumption
     * (descriptive only; it does not project future spending).
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

        [$status, $label, $tone, $message] = $this->burnStatus(
            $start,
            $budget,
            $spent,
            $variance
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
        ];
    }

    /**
     * Receipt/document compliance across all expenses of a project.
     */
    public function compliance(Project $project): array
    {
        return $this->financials->compliance($project);
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
            'tranche_name' => $approval->tranche?->releaseLabel(),
            'type' => $approval->type,
            'type_label' => $approval->typeLabel(),
            'title' => $approval->displayTitle(),
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
        float $budget,
        float $spent,
        float $variance
    ): array {
        if ($start->isFuture()) {
            return [
                'not_started',
                'Not Started',
                'neutral',
                'The project has not started yet; burn rate will appear once implementation begins.',
            ];
        }

        // Descriptive only: compares what has been spent with how much of
        // the project period has passed. No projection of future spending.
        if ($budget > 0 && ($spent > $budget || $variance > 15)) {
            $message = $spent > $budget
                ? 'Spending has passed the approved budget.'
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

    /**
     * Public program label for the Projects page. The schema has no program
     * column, so the label is derived from the funding source.
     */
    protected function programLabel(Project $project): string
    {
        $funder = $project->fundingAgency
            ? ($project->fundingAgency->acronym ?: $project->fundingAgency->name)
            : 'External';

        $programs = [
            'MOST-BARMM' => 'MOST R&D Program',
            'DOST-PCAARRD' => 'DOST-PCAARRD Program',
            'CHED' => 'CHED Research Program',
            'DOST-PCHRD' => 'DOST-PCHRD Program',
        ];

        return $programs[$funder] ?? $funder . ' Program';
    }

    protected function fundingAgencyName(Project $project): string
    {
        if ($project->fundingAgency) {
            return $project->fundingAgency->acronym ?: $project->fundingAgency->name;
        }

        return 'External Funding Agency';
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
        return Percent::of($part, $whole);
    }
}

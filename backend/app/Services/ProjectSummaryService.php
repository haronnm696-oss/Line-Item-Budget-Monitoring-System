<?php

namespace App\Services;

use App\Models\Project;

/**
 * Read-only budget summary of one project for any of its members
 * (Researcher Overview and Budget, Finance/Admin Overview). Built from the
 * same calls as the Project Leader Overview, so every role sees the same
 * total budget, spent, remaining, monthly trend and PS vs MOOE.
 * Descriptive only.
 */
class ProjectSummaryService
{
    public function __construct(
        private ProjectFinancialService $financials,
        private ProgramDashboardService $program,
        private ProjectLeaderService $leader
    ) {
    }

    public function summary(Project $project): array
    {
        $project->loadMissing('fundingAgency');
        $metrics = $this->financials->financials($project);
        $compliance = $this->financials->compliance($project);

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'title' => $project->title,
                'funder' => $project->fundingAgency ? ($project->fundingAgency->acronym ?: $project->fundingAgency->name) : null,
                // The counterpart's owner (Form B field, else the IMPLEMENTING_AGENCY setting).
                'implementing_agency' => ProjectFinancialService::implementingAgencyName($project),
                'status' => ucfirst($this->financials->derivedStatus($project)),
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'project_leader' => $project->usersWithRole('project-leader')->orderBy('name')->pluck('name')->join(', ') ?: null,
            ],
            'kpis' => [
                'total_budget' => $metrics['budget'],
                // Sums of the line items (no separately typed figure).
                'funder_share' => $this->financials->fundingTotals($project)['funder'],
                'counterpart' => $this->financials->fundingTotals($project)['implementing_agency'],
                'total_spent' => $metrics['spent'],
                'remaining' => $metrics['remaining'],
                'utilization_pct' => $metrics['utilization_pct'],
                'verified_transactions' => $this->leader->spentRows($project)->count(),
                'verified_with_receipt' => $compliance['verified_with_receipt'],
            ],
            'monthly_expenses' => $this->program->monthlyExpenses(collect([$project]), now()->startOfDay()),
            'category_summary' => $this->program->categorySummary(collect([$project]), $metrics['budget']),
            'line_items' => $this->financials->categoryRows($project),
            'transactions' => $this->leader->spentRows($project)->take(5)->values()->all(),
        ];
    }
}

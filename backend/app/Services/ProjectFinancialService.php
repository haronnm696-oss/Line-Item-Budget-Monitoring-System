<?php

namespace App\Services;

use App\Models\LibCategory;
use App\Models\Project;

/**
 * Canonical financial calculations for a project.
 *
 * Every page (Dashboard, Projects, Reports, Program Leader dashboard) must
 * read budget, spent, remaining, utilization, and receipt compliance from
 * this service so the numbers can never drift between screens.
 *
 * Spent is deliberately limited to verified expense records. Payroll is a
 * separate module and is not part of the LIB expense utilization figures.
 */
class ProjectFinancialService
{
    /**
     * Approved project budget. Falls back to the sum of line items only when
     * the project has no approved budget recorded.
     */
    public function budget(Project $project): float
    {
        $budget = (float) $project->total_approved_budget;

        if ($budget <= 0) {
            $budget = (float) $project->libCategories()->sum('allocated_amount');
        }

        return round($budget, 2);
    }

    /**
     * Spend is the sum of verified expense records only.
     */
    public function spent(Project $project): float
    {
        return round((float) $project->expenses()
            ->where('status', 'verified')
            ->sum('amount'), 2);
    }

    /**
     * @return array{budget: float, spent: float, remaining: float, utilization_pct: float, expenses_count: int, receipts_count: int, receipt_compliance_pct: float|null}
     */
    public function financials(Project $project): array
    {
        $budget = $this->budget($project);
        $spent = $this->spent($project);
        $expenseCount = (int) $project->expenses()->count();
        $receiptsCount = (int) $project->expenses()
            ->whereNotNull('attachment_path')
            ->where('attachment_path', '<>', '')
            ->count();

        return [
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => round($budget - $spent, 2),
            'utilization_pct' => $this->pct($spent, $budget),
            'expenses_count' => $expenseCount,
            'receipts_count' => $receiptsCount,
            'receipt_compliance_pct' => $expenseCount > 0
                ? round(($receiptsCount / $expenseCount) * 100, 1)
                : null,
        ];
    }

    /**
     * Line-item rows for the utilization report and project detail.
     *
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
     * Receipt/document compliance for one project. `compliance_pct` is null
     * when the project has no expense records, so the UI can render "—"
     * instead of a misleading 0%.
     *
     * @return array<string, mixed>
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

        $compliancePct = $total > 0
            ? round(($withReceipt / $total) * 100, 1)
            : null;
        $verifiedPct = $total > 0
            ? round(($verifiedWithReceipt / $total) * 100, 1)
            : null;
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
     * Status derived from the project period so demo data never goes stale.
     */
    public function derivedStatus(Project $project): string
    {
        $today = now()->startOfDay();

        if ($project->start_date && $project->start_date->greaterThan($today)) {
            return 'planning';
        }

        if ($project->end_date && $project->end_date->lessThan($today)) {
            return 'completed';
        }

        return 'ongoing';
    }

    protected function complianceLabel(int $total, ?float $pct): string
    {
        if ($total === 0 || $pct === null) {
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

    protected function complianceTone(int $total, ?float $pct): string
    {
        if ($total === 0 || $pct === null) {
            return 'neutral';
        }

        if ($pct >= 90) {
            return 'ok';
        }

        return $pct >= 70 ? 'warn' : 'over';
    }

    protected function pct(float $part, float $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}

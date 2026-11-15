<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\PurchaseRequest;

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

    /** The only expense status that counts as spent. */
    public const SPENT_STATUS = 'verified';

    /**
     * The single rule for "spent": only verified expense records count
     * (payroll is excluded). Every screen, report and command that sums or
     * lists spending applies this to its expense query, so the figures can
     * never drift apart.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     * @param T $expenses
     * @return T
     */
    public static function onlySpent($expenses)
    {
        return $expenses->where('status', self::SPENT_STATUS);
    }

    /**
     * The single receipt rule: an expense has a receipt when a "receipt"
     * document is attached to it (documents table). Receipt compliance is
     * measured on verified (spent) expenses: a verified expense without a
     * receipt document is a missing receipt.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     * @param T $expenses
     * @return T
     */
    public static function withReceipt($expenses)
    {
        return $expenses->whereHas('receipts');
    }

    /** Verified expenses that have no receipt document. */
    public static function missingReceipt($expenses)
    {
        return self::onlySpent($expenses)->whereDoesntHave('receipts');
    }

    /**
     * Spend is the sum of verified expense records only.
     */
    public function spent(Project $project): float
    {
        return round((float) self::onlySpent($project->expenses())->sum('amount'), 2);
    }

    /**
     * receipts_count / verified_count is the receipt compliance of the
     * verified (spent) expenses; null when nothing is verified yet.
     *
     * @return array{budget: float, spent: float, remaining: float, utilization_pct: float, expenses_count: int, verified_count: int, receipts_count: int, receipt_compliance_pct: float|null}
     */
    public function financials(Project $project): array
    {
        $budget = $this->budget($project);
        $spent = $this->spent($project);
        $expenseCount = (int) $project->expenses()->count();
        $verifiedCount = (int) self::onlySpent($project->expenses())->count();
        $receiptsCount = (int) self::withReceipt(self::onlySpent($project->expenses()))->count();

        return [
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => round($budget - $spent, 2),
            'utilization_pct' => $this->pct($spent, $budget),
            'expenses_count' => $expenseCount,
            'verified_count' => $verifiedCount,
            'receipts_count' => $receiptsCount,
            'receipt_compliance_pct' => $verifiedCount > 0
                ? round(($receiptsCount / $verifiedCount) * 100, 1)
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

    /** Workflow stages that are waiting for a decision (not yet verified). */
    public const PENDING_STAGES = ['submitted', 'endorsed'];

    /**
     * Balancing query per line item: allocated, spent (verified expenses),
     * pending (submitted + endorsed expenses and purchase requests, not yet
     * verified) and available = allocated - spent - pending. Payroll is not
     * included.
     *
     * @return array{line_items: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function balancing(Project $project, ?int $lineItemId = null): array
    {
        $sums = function ($query, string $column) use ($project) {
            return $query->where('project_id', $project->id)
                ->selectRaw("lib_category_id, SUM({$column}) as total")
                ->groupBy('lib_category_id')
                ->pluck('total', 'lib_category_id')
                ->map(fn ($v) => round((float) $v, 2));
        };

        $spent = $sums(self::onlySpent(Expense::query()), 'amount');
        $pendingExpenses = $sums(Expense::query()->whereIn('workflow_status', self::PENDING_STAGES), 'amount');
        $pendingRequests = $sums(PurchaseRequest::query()->whereIn('workflow_status', self::PENDING_STAGES), 'estimated_amount');

        $rows = $project->libCategories()
            ->when($lineItemId, fn ($q) => $q->whereKey($lineItemId))
            ->orderBy('category_type')->orderBy('id')
            ->get()
            ->map(function (LibCategory $item) use ($spent, $pendingExpenses, $pendingRequests) {
                $allocated = round((float) $item->allocated_amount, 2);
                $itemSpent = $spent[$item->id] ?? 0.0;
                $fromExpenses = $pendingExpenses[$item->id] ?? 0.0;
                $fromRequests = $pendingRequests[$item->id] ?? 0.0;
                $pending = round($fromExpenses + $fromRequests, 2);

                return [
                    'id' => $item->id,
                    'type' => $item->category_type,
                    'code' => $item->line_item_code,
                    'name' => $item->line_item_name,
                    'allocated' => $allocated,
                    'spent' => $itemSpent,
                    'pending_expenses' => $fromExpenses,
                    'pending_purchase_requests' => $fromRequests,
                    'pending' => $pending,
                    'available' => round($allocated - $itemSpent - $pending, 2),
                ];
            })
            ->values();

        $total = fn (string $key) => round((float) $rows->sum($key), 2);

        return [
            'line_items' => $rows->all(),
            'totals' => [
                'allocated' => $total('allocated'),
                'spent' => $total('spent'),
                'pending' => $total('pending'),
                'available' => $total('available'),
            ],
        ];
    }

    /**
     * PS and MOOE totals built from categoryRows(), so the split always adds
     * up to the line items shown on the project detail and Form B.
     *
     * @return array{PS: array{allocated: float, spent: float, utilization_pct: float|null}, MOOE: array{allocated: float, spent: float, utilization_pct: float|null}}
     */
    public function bucketTotals(Project $project): array
    {
        $rows = collect($this->categoryRows($project));

        $bucket = function (string $type) use ($rows) {
            $items = $rows->where('type', $type);
            $allocated = round((float) $items->sum('allocated'), 2);
            $spent = round((float) $items->sum('spent'), 2);

            return [
                'allocated' => $allocated,
                'spent' => $spent,
                // null (not 0) when there is no budget line, so the UI shows "—".
                'utilization_pct' => $allocated > 0 ? $this->pct($spent, $allocated) : null,
            ];
        };

        return ['PS' => $bucket('PS'), 'MOOE' => $bucket('MOOE')];
    }

    /**
     * Receipt/document compliance for one project (see withReceipt()).
     * `compliance_pct` is the share of verified expenses that have a receipt
     * document; null when nothing is verified yet, so the UI can render "—"
     * instead of a misleading 0%. `missing_receipt` counts verified expenses
     * with no receipt document.
     *
     * @return array<string, mixed>
     */
    public function compliance(Project $project): array
    {
        $expenses = $project->expenses()
            ->with(['libCategory', 'verifiedBy'])
            ->withCount('receipts')
            ->get();

        $hasReceipt = fn ($e) => $e->receipts_count > 0;
        $isVerified = fn ($e) => $e->status === self::SPENT_STATUS;

        $total = $expenses->count();
        $withReceipt = $expenses->filter($hasReceipt)->count();
        $verified = $expenses->filter($isVerified)->count();
        $pending = $expenses->where('status', 'pending')->count();
        $rejected = $expenses->where('status', 'rejected')->count();
        $verifiedWithReceipt = $expenses
            ->filter(fn ($e) => $isVerified($e) && $hasReceipt($e))
            ->count();

        $compliancePct = $verified > 0
            ? round(($verifiedWithReceipt / $verified) * 100, 1)
            : null;
        $verifiedPct = $total > 0
            ? round(($verifiedWithReceipt / $total) * 100, 1)
            : null;
        $missing = $expenses
            ->filter(fn ($e) => $isVerified($e) && ! $hasReceipt($e))
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

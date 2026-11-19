<?php

namespace App\Services;

use App\Support\Percent;
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
                    'cost_type' => $category->cost_type,
                    'group' => $category->expense_group,
                    'subgroup' => $category->expense_subgroup,
                    'computation' => $category->computationText(),
                    'implementing_agency_amount' => round((float) $category->implementing_agency_amount, 2),
                    'funder_amount' => round((float) $category->funder_amount, 2),
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
     * Balancing query per line item and funding source: allocated, spent,
     * pending and available = allocated - spent - pending. Payroll is not
     * included. Each expense and purchase request draws from one source of
     * its line item (funding_source: the funder by default, or the
     * implementing agency counterpart); row totals are both sources summed.
     *
     *   spent    verified expenses
     *   pending  expenses submitted or approved by the Project Leader, plus
     *            any not-yet-verified expense linked to a purchase request
     *            (it took over that request's money), plus purchase
     *            requests that are approved and still open (not fulfilled,
     *            not cancelled)
     *
     * A fulfilled request stops counting and its linked expense counts
     * instead, so the same money is never counted twice.
     *
     * @return array{line_items: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function balancing(Project $project, ?int $lineItemId = null): array
    {
        $sums = function ($query, string $column) use ($project) {
            return $query->where('project_id', $project->id)
                ->selectRaw("lib_category_id, funding_source, SUM({$column}) as total")
                ->groupBy('lib_category_id', 'funding_source')
                ->get()
                ->groupBy(fn ($row) => $row->lib_category_id . ':' . self::source($row->funding_source))
                ->map(fn ($group) => round((float) $group->sum('total'), 2));
        };

        $spent = $sums(self::onlySpent(Expense::query()), 'amount');
        $pendingExpenses = $sums(Expense::query()->where(function ($q) {
            $q->whereIn('workflow_status', self::PENDING_STAGES)
                ->orWhere(fn ($linked) => $linked->whereNotNull('purchase_request_id')->whereIn('workflow_status', ['draft', 'returned']));
        }), 'amount');
        $pendingRequests = $sums(PurchaseRequest::query()->where('workflow_status', PurchaseRequest::OPEN_APPROVED), 'estimated_amount');

        $rows = $project->libCategories()
            ->when($lineItemId, fn ($q) => $q->whereKey($lineItemId))
            ->orderBy('category_type')->orderBy('id')
            ->get()
            ->map(function (LibCategory $item) use ($spent, $pendingExpenses, $pendingRequests) {
                $sources = [];
                foreach (LibCategory::SOURCES as $source) {
                    $key = $item->id . ':' . $source;
                    $allocated = $item->amountFor($source);
                    $itemSpent = $spent[$key] ?? 0.0;
                    $fromExpenses = $pendingExpenses[$key] ?? 0.0;
                    $fromRequests = $pendingRequests[$key] ?? 0.0;
                    $pending = round($fromExpenses + $fromRequests, 2);
                    $sources[$source] = [
                        'allocated' => $allocated,
                        'spent' => $itemSpent,
                        'pending_expenses' => $fromExpenses,
                        'pending_purchase_requests' => $fromRequests,
                        'pending' => $pending,
                        'available' => round($allocated - $itemSpent - $pending, 2),
                    ];
                }
                $sum = fn (string $key) => round(array_sum(array_column($sources, $key)), 2);

                return [
                    'id' => $item->id,
                    'type' => $item->category_type,
                    'cost_type' => $item->cost_type,
                    'group' => $item->expense_group,
                    'subgroup' => $item->expense_subgroup,
                    'code' => $item->line_item_code,
                    'name' => $item->line_item_name,
                    'allocated' => round((float) $item->allocated_amount, 2),
                    'spent' => $sum('spent'),
                    'pending_expenses' => $sum('pending_expenses'),
                    'pending_purchase_requests' => $sum('pending_purchase_requests'),
                    'pending' => $sum('pending'),
                    'available' => $sum('available'),
                    'has_counterpart' => $item->hasCounterpart(),
                    'sources' => $sources,
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
     * One funding source of one line item from balancing() (allocated,
     * spent, pending, available) plus the item's name. Null when the item is
     * not on the project.
     *
     * @return array<string, mixed>|null
     */
    public function sourceBalance(Project $project, int $lineItemId, ?string $source): ?array
    {
        $row = $this->balancing($project, $lineItemId)['line_items'][0] ?? null;
        if (! $row) {
            return null;
        }
        $source = self::source($source);

        return $row['sources'][$source] + ['name' => $row['name'], 'source' => $source, 'has_counterpart' => $row['has_counterpart']];
    }

    /** Unknown or empty funding source values mean the funder. */
    public static function source(?string $source): string
    {
        return in_array($source, LibCategory::SOURCES, true) ? $source : LibCategory::SOURCE_FUNDER;
    }

    /**
     * Funder and counterpart totals are the sums of the line items; there is
     * no separately typed figure that could disagree with them.
     *
     * @return array{implementing_agency: float, funder: float, total: float}
     */
    public function fundingTotals(Project $project): array
    {
        $counterpart = round((float) $project->libCategories()->sum('implementing_agency_amount'), 2);
        $funder = round((float) $project->libCategories()->sum('funder_amount'), 2);

        return ['implementing_agency' => $counterpart, 'funder' => $funder, 'total' => round($counterpart + $funder, 2)];
    }

    /**
     * Column labels: the project's funder short name (acronym, else its full
     * name) and the implementing agency. Never a hard-coded funder.
     *
     * @return array{funder: string, funder_name: string|null, implementing_agency: string}
     */
    public static function sourceLabels(Project $project): array
    {
        $agency = $project->fundingAgency;

        return [
            'funder' => $agency ? (trim((string) $agency->acronym) ?: $agency->name) : 'Funder',
            'funder_name' => $agency?->name,
            'implementing_agency' => (string) config('app.implementing_agency', 'Implementing agency'),
        ];
    }

    public const COST_TYPE_LABELS = ['Direct' => 'Direct Cost', 'Indirect' => 'Indirect Cost'];
    public const CATEGORY_LABELS = ['PS' => 'Personal Services', 'MOOE' => 'Maintenance and Other Operating Expenses'];

    /**
     * The line items laid out like R&D Form B: PS then MOOE, each split into
     * Direct and Indirect cost, then expense groups (with one optional
     * sub-group level), with sub-totals per source at every level and grand
     * totals. Levels without line items are left out.
     *
     * @return array<string, mixed>
     */
    public function formB(Project $project): array
    {
        $project->loadMissing('fundingAgency');
        $items = $project->libCategories()->orderBy('id')->get();
        $spent = self::onlySpent(Expense::query())->where('project_id', $project->id)
            ->selectRaw('lib_category_id, SUM(amount) as total')->groupBy('lib_category_id')
            ->pluck('total', 'lib_category_id');

        $row = fn (LibCategory $item) => [
            'id' => $item->id,
            'type' => $item->category_type,
            'cost_type' => $item->cost_type ?: 'Direct',
            'group' => $item->expense_group ?: $item->line_item_name,
            'subgroup' => $item->expense_subgroup,
            'name' => $item->line_item_name,
            'code' => $item->line_item_code,
            'quantity' => $item->quantity,
            'unit_description' => $item->unit_description,
            'rate' => $item->rate !== null ? round((float) $item->rate, 2) : null,
            'rate_period' => $item->rate_period,
            'periods' => $item->periods,
            'computed_amount' => $item->computedAmount(),
            'computation' => $item->computationText(),
            'implementing_agency_amount' => round((float) $item->implementing_agency_amount, 2),
            'funder_amount' => round((float) $item->funder_amount, 2),
            'allocated' => round((float) $item->allocated_amount, 2),
            'spent' => round((float) ($spent[$item->id] ?? 0), 2),
        ];
        $rows = $items->map($row);
        $totals = function ($subset) {
            $counterpart = round((float) $subset->sum('implementing_agency_amount'), 2);
            $funder = round((float) $subset->sum('funder_amount'), 2);

            return ['implementing_agency' => $counterpart, 'funder' => $funder, 'total' => round($counterpart + $funder, 2)];
        };

        $sections = [];
        foreach (array_keys(self::CATEGORY_LABELS) as $type) {
            $inType = $rows->where('type', $type);
            $costTypes = [];
            foreach (LibCategory::COST_TYPES as $costType) {
                $inCost = $inType->where('cost_type', $costType);
                if ($inCost->isEmpty()) {
                    continue;
                }
                $groups = $inCost->groupBy('group')->map(fn ($inGroup, $name) => [
                    'name' => $name,
                    'items' => $inGroup->filter(fn ($item) => trim((string) $item['subgroup']) === '')->values()->all(),
                    'subgroups' => $inGroup->filter(fn ($item) => trim((string) $item['subgroup']) !== '')
                        ->groupBy('subgroup')
                        ->map(fn ($inSub, $subName) => ['name' => $subName, 'items' => $inSub->values()->all(), 'subtotal' => $totals($inSub)])
                        ->values()->all(),
                    'subtotal' => $totals($inGroup),
                ])->values()->all();

                $costTypes[] = ['cost_type' => $costType, 'label' => self::COST_TYPE_LABELS[$costType], 'groups' => $groups, 'subtotal' => $totals($inCost)];
            }
            if ($costTypes !== []) {
                $sections[] = ['type' => $type, 'label' => self::CATEGORY_LABELS[$type], 'cost_types' => $costTypes, 'subtotal' => $totals($inType)];
            }
        }

        return [
            'labels' => self::sourceLabels($project),
            'sections' => $sections,
            'grand_total' => $totals($rows),
            'warnings' => $this->fundingWarnings($project, $totals($rows)),
        ];
    }

    /**
     * Older projects carry a typed counterpart and funder amount
     * (projects.implementing_agency_counterpart / funding_agency_amount)
     * from before amounts lived on line items. When a typed figure differs
     * from what the line items assign, say so; nothing is changed
     * automatically, and the warning goes once they match. A figure that was
     * never typed (0) is not compared.
     *
     * @param array{implementing_agency: float, funder: float}|null $assigned line-item sums (fundingTotals() when null)
     * @return array<int, array{source: string, typed: float, assigned: float, message: string}>
     */
    public function fundingWarnings(Project $project, ?array $assigned = null): array
    {
        $assigned ??= $this->fundingTotals($project);
        $labels = self::sourceLabels($project);
        $checks = [
            LibCategory::SOURCE_COUNTERPART => ['implementing_agency_counterpart', 'Counterpart'],
            LibCategory::SOURCE_FUNDER => ['funding_agency_amount', "Funder amount ({$labels['funder']})"],
        ];

        $warnings = [];
        foreach ($checks as $source => [$column, $what]) {
            $typed = round((float) $project->{$column}, 2);
            $sum = round((float) $assigned[$source], 2);
            if ($typed > 0 && abs($typed - $sum) >= 0.005) {
                $warnings[] = [
                    'source' => $source,
                    'typed' => $typed,
                    'assigned' => $sum,
                    'message' => sprintf('%s typed for this project (₱%s) is not yet assigned to line items (₱%s assigned).', $what, number_format($typed, 2), number_format($sum, 2)),
                ];
            }
        }

        return $warnings;
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
        return Percent::of($part, $whole);
    }
}

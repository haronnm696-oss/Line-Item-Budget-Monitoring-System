<?php

namespace App\Services;

use App\Support\Percent;
use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectPersonnel;
use App\Models\PurchaseRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Canonical financial calculations for a project.
 *
 * Every page (Dashboard, Projects, Reports, Program Leader dashboard) must
 * read budget, spent, remaining, utilization, and receipt compliance from
 * this service so the numbers can never drift between screens.
 *
 *   Spent   = verified expenses + verified payroll
 *   Pending = expenses and payroll waiting for approval or verification
 *             (plus open approved purchase requests, see balancing())
 *
 * Payroll is charged to each row's PS line item (the person's linked line
 * item), from the funder's share. Its amount is the gross pay (for current
 * batches gross = net: honoraria have no deductions). Receipt compliance is
 * about expenses only.
 */
class ProjectFinancialService
{
    /** Payroll rows count as spent once their batch is verified (the rows mirror the batch). */
    public const PAYROLL_SPENT_STAGE = 'verified';

    /** Payroll always draws from the funder's share of its line item. */
    public const PAYROLL_SOURCE = LibCategory::SOURCE_FUNDER;

    public const SPENT_RULE = 'Spent = verified expenses and verified payroll';
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
     * The expense half of "spent": only verified expense records count. The
     * payroll half is onlySpentPayroll(). Every screen, report and command
     * that sums spending goes through spent() / spentByLineItem() /
     * spentEntries(), so the figures can never drift apart.
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
     * The payroll half of "spent": rows of verified batches.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     * @param T $payrolls
     * @return T
     */
    public static function onlySpentPayroll($payrolls)
    {
        return $payrolls->where('workflow_status', self::PAYROLL_SPENT_STAGE);
    }

    /** Payroll rows waiting for approval or verification (submitted, or approved by the Project Leader). */
    public static function onlyPendingPayroll($payrolls)
    {
        return $payrolls->whereIn('workflow_status', self::PENDING_STAGES);
    }

    /**
     * Spent per line item: [lib_category_id => verified expenses + verified payroll].
     *
     * @return Collection<int, float>
     */
    public static function spentByLineItem(int $projectId): Collection
    {
        $expenses = self::onlySpent(Expense::query())->where('project_id', $projectId)
            ->selectRaw('lib_category_id, SUM(amount) as total')->groupBy('lib_category_id')
            ->pluck('total', 'lib_category_id');
        $payroll = self::onlySpentPayroll(Payroll::query())->where('project_id', $projectId)
            ->selectRaw('lib_category_id, SUM(gross_pay) as total')->groupBy('lib_category_id')
            ->pluck('total', 'lib_category_id');

        return $expenses->keys()->merge($payroll->keys())->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => round((float) ($expenses[$id] ?? 0) + (float) ($payroll[$id] ?? 0), 2)]);
    }

    /**
     * Verified payroll as spent entries, one per batch and line item, for
     * lists, ledgers and monthly charts. `date` is the last day of the pay
     * period, or the verification date when that is earlier.
     *
     * @param array<int, int> $projectIds
     * @return Collection<int, array<string, mixed>>
     */
    public static function payrollEntries(array $projectIds): Collection
    {
        if ($projectIds === []) {
            return collect();
        }

        return self::onlySpentPayroll(Payroll::query())->whereIn('project_id', $projectIds)
            ->with(['batch.verifiedBy', 'batch.preparedBy', 'libCategory'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Payroll $row) => ($row->payroll_batch_id ? 'b' . $row->payroll_batch_id : 'r' . $row->id) . ':' . $row->lib_category_id)
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $batch = $first->batch;
                $end = $batch ? $batch->periodEnd()->copy()->endOfMonth() : Carbon::create($first->pay_year, $first->pay_month, 1)->endOfMonth();
                $verifiedAt = $batch?->verified_at ?? $first->verified_at;
                $date = $verifiedAt && Carbon::parse($verifiedAt)->lt($end) ? Carbon::parse($verifiedAt) : $end;
                $label = $batch ? $batch->monthLabel() : Carbon::create($first->pay_year, $first->pay_month, 1)->format('F Y');
                $people = $rows->count();

                return [
                    'kind' => 'payroll',
                    'key' => 'payroll-' . ($batch?->id ?? 'r' . $first->id) . '-' . $first->lib_category_id,
                    'project_id' => (int) $first->project_id,
                    'batch_id' => $batch?->id,
                    'lib_category_id' => (int) $first->lib_category_id,
                    'line_item' => $first->libCategory?->line_item_name,
                    'type' => $first->libCategory?->category_type,
                    'amount' => round((float) $rows->sum('gross_pay'), 2),
                    'date' => $date->toDateString(),
                    'description' => 'Payroll ' . $label . ' (' . $people . ' ' . ($people === 1 ? 'person' : 'people') . ')',
                    'reference_no' => $batch ? $batch->reference() : 'PAY-' . $first->monthKey(),
                    'people' => $people,
                    'recorded_by' => $batch?->preparedBy?->name,
                    'verified_by' => $batch?->verifiedBy?->name,
                    'verified_at' => $verifiedAt ? Carbon::parse($verifiedAt)->toIso8601String() : null,
                ];
            })
            ->values();
    }

    /**
     * Every spent amount of the projects as [kind, project_id,
     * lib_category_id, type (PS / MOOE / null), amount, date]: verified
     * expenses (by expense date) and verified payroll (payrollEntries()).
     *
     * @param array<int, int> $projectIds
     * @return Collection<int, array<string, mixed>>
     */
    public static function spentEntries(array $projectIds): Collection
    {
        if ($projectIds === []) {
            return collect();
        }
        $expenses = self::onlySpent(Expense::query())->whereIn('project_id', $projectIds)
            ->with('libCategory:id,category_type')
            ->get(['id', 'project_id', 'lib_category_id', 'amount', 'expense_date'])
            ->map(fn (Expense $e) => [
                'kind' => 'expense',
                'project_id' => (int) $e->project_id,
                'lib_category_id' => $e->lib_category_id ? (int) $e->lib_category_id : null,
                'type' => $e->libCategory?->category_type,
                'amount' => round((float) $e->amount, 2),
                'date' => $e->expense_date?->toDateString(),
            ]);

        return $expenses->concat(self::payrollEntries($projectIds)->map(fn (array $p) => array_intersect_key($p, array_flip(['kind', 'project_id', 'lib_category_id', 'type', 'amount', 'date']))))->values();
    }

    /**
     * Honoraria lines: PS line items that personnel (Special Orders) are
     * linked to. They are paid through Payroll only, never through expenses,
     * so the same pay is never counted twice.
     *
     * @return array<int, int>
     */
    public static function honorariaLineIds(int $projectId): array
    {
        $ps = LibCategory::query()->where('project_id', $projectId)->where('category_type', 'PS')->pluck('id');

        return ProjectPersonnel::query()->where('project_id', $projectId)
            ->whereIn('lib_category_id', $ps)
            ->distinct()->pluck('lib_category_id')
            ->map(fn ($id) => (int) $id)->values()->all();
    }

    public const HONORARIA_LINE = 'Honoraria are paid through Payroll.';

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

    /** Spent = verified expenses + verified payroll. */
    public function spent(Project $project): float
    {
        return round($this->spentExpenses($project) + $this->spentPayroll($project), 2);
    }

    public function spentExpenses(Project $project): float
    {
        return round((float) self::onlySpent($project->expenses())->sum('amount'), 2);
    }

    public function spentPayroll(Project $project): float
    {
        return round((float) self::onlySpentPayroll($project->payrolls())->sum('gross_pay'), 2);
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
        $spentExpenses = $this->spentExpenses($project);
        $spentPayroll = $this->spentPayroll($project);
        $spent = round($spentExpenses + $spentPayroll, 2);
        $expenseCount = (int) $project->expenses()->count();
        $verifiedCount = (int) self::onlySpent($project->expenses())->count();
        $receiptsCount = (int) self::withReceipt(self::onlySpent($project->expenses()))->count();

        return [
            'budget' => $budget,
            'spent' => $spent,
            // The two parts of spent (they add up to it).
            'spent_expenses' => $spentExpenses,
            'spent_payroll' => $spentPayroll,
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
     * pending and available = allocated - spent - pending. Each expense and
     * purchase request draws from one source of its line item
     * (funding_source: the funder by default, or the implementing agency
     * counterpart); payroll always draws from the funder. Row totals are both
     * sources summed.
     *
     *   spent    verified expenses + verified payroll
     *   pending  expenses submitted or approved by the Project Leader, plus
     *            any not-yet-verified expense linked to a purchase request
     *            (it took over that request's money), plus purchase
     *            requests that are approved and still open (not fulfilled,
     *            not cancelled), plus payroll submitted or approved by the
     *            Project Leader (waiting for verification)
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
        // Payroll has no funding_source column: it is the funder's share.
        $payrollSums = fn ($query) => $query->where('project_id', $project->id)
            ->selectRaw('lib_category_id, SUM(gross_pay) as total')->groupBy('lib_category_id')
            ->pluck('total', 'lib_category_id')
            ->mapWithKeys(fn ($total, $id) => [$id . ':' . self::PAYROLL_SOURCE => round((float) $total, 2)]);
        $spentPayroll = $payrollSums(self::onlySpentPayroll(Payroll::query()));
        $pendingPayroll = $payrollSums(self::onlyPendingPayroll(Payroll::query()));
        $honoraria = self::honorariaLineIds($project->id);

        $rows = $project->libCategories()
            ->when($lineItemId, fn ($q) => $q->whereKey($lineItemId))
            ->orderBy('category_type')->orderBy('id')
            ->get()
            ->map(function (LibCategory $item) use ($spent, $pendingExpenses, $pendingRequests, $spentPayroll, $pendingPayroll, $honoraria) {
                $sources = [];
                foreach (LibCategory::SOURCES as $source) {
                    $key = $item->id . ':' . $source;
                    $allocated = $item->amountFor($source);
                    $fromPayroll = $spentPayroll[$key] ?? 0.0;
                    $itemSpent = round(($spent[$key] ?? 0.0) + $fromPayroll, 2);
                    $fromExpenses = $pendingExpenses[$key] ?? 0.0;
                    $fromRequests = $pendingRequests[$key] ?? 0.0;
                    $payrollWaiting = $pendingPayroll[$key] ?? 0.0;
                    $pending = round($fromExpenses + $fromRequests + $payrollWaiting, 2);
                    $sources[$source] = [
                        'allocated' => $allocated,
                        'spent' => $itemSpent,
                        'spent_payroll' => $fromPayroll,
                        'pending_expenses' => $fromExpenses,
                        'pending_purchase_requests' => $fromRequests,
                        'pending_payroll' => $payrollWaiting,
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
                    'spent_payroll' => $sum('spent_payroll'),
                    'pending_expenses' => $sum('pending_expenses'),
                    'pending_purchase_requests' => $sum('pending_purchase_requests'),
                    'pending_payroll' => $sum('pending_payroll'),
                    'pending' => $sum('pending'),
                    'available' => $sum('available'),
                    'has_counterpart' => $item->hasCounterpart(),
                    // Personnel are linked to it: paid through Payroll, never recorded as an expense.
                    'honoraria' => in_array((int) $item->id, $honoraria, true),
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
                'spent_payroll' => $total('spent_payroll'),
                'pending' => $total('pending'),
                'pending_payroll' => $total('pending_payroll'),
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
        $spent = self::spentByLineItem($project->id);

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

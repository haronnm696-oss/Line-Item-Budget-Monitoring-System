<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Support\PurchaseRequestItems;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read-only views for the Project Leader pages (Overview, Submissions,
 * Transactions). Every figure comes from the existing services:
 * ProjectFinancialService (budget, spent, remaining, PS/MOOE, receipts),
 * DashboardService (burn rate, tranches), ProgramDashboardService (monthly
 * trend) and PayrollService (who may approve a batch, what it waits for).
 * Approve / return go through the existing workflow endpoints.
 *
 * Review queue tabs (one project):
 *   waiting   submitted expenses, payroll batches and purchase requests
 *   returned  records sent back to their maker (status "returned")
 *   approved  records this Project Leader approved (any later stage)
 * The Overview "To review" count is the size of the "waiting" tab.
 */
class ProjectLeaderService
{
    public const TYPES = ['expense', 'payroll', 'purchase_request'];
    public const TABS = ['waiting', 'returned', 'approved'];
    public const PER_PAGE = 25;

    private const TYPE_LABELS = [
        'expense' => 'Expense',
        'payroll' => 'Payroll',
        'purchase_request' => 'Purchase request',
    ];

    /** Stages that come after the Project Leader's approval. */
    private const AFTER_APPROVAL = [
        'expense' => ['endorsed', 'verified'],
        'payroll' => ['endorsed', 'verified'],
        'purchase_request' => ['endorsed', 'fulfilled', 'cancelled'],
    ];

    public function __construct(
        private ProjectFinancialService $financials,
        private DashboardService $dashboard,
        private ProgramDashboardService $program,
        private PayrollService $payroll
    ) {
    }

    /** 403 unless the user is a Project Leader of this project. */
    public function requireLeader(Project $project, User $user): void
    {
        abort_unless($user->hasRoleOn($project, 'project-leader'), 403, 'Only the Project Leader of this project can open this page.');
    }

    // ---------------------------------------------------------------- review queue

    /**
     * One tab of the queue (optionally one record type) plus the count of
     * every tab for the same type filter.
     *
     * @return array{counts: array<string, int>, items: array<int, array<string, mixed>>}
     */
    public function queue(Project $project, User $user, string $tab = 'waiting', ?string $type = null): array
    {
        $types = $type ? [$type] : self::TYPES;
        $counts = [];
        foreach (self::TABS as $name) {
            $counts[$name] = array_sum(array_map(fn ($t) => $this->query($project, $user, $t, $name)->count(), $types));
        }

        $items = collect($types)
            ->flatMap(fn ($t) => $this->query($project, $user, $t, $tab)->get()->map(fn ($record) => $this->item($t, $record, $user)))
            ->values();

        // Waiting: oldest first. Returned / approved: newest decision first.
        $items = $tab === 'waiting'
            ? $items->sortBy(fn ($item) => [$item['submitted_at'] ?? '', $item['key']])
            : $items->sortByDesc(fn ($item) => [$item['decided_at'] ?? '', $item['key']]);

        return ['counts' => $counts, 'items' => $items->values()->all()];
    }

    // ---------------------------------------------------------------- sidebar badges

    /**
     * submissions: the "waiting" tab count (same query as queue() and the
     *              Overview "To review" KPI)
     * requests:    budget requests of this project decided since the leader
     *              last opened My requests (their unread decision notifications)
     */
    public function badges(Project $project, User $user): array
    {
        return [
            'submissions' => array_sum(array_map(fn ($t) => $this->query($project, $user, $t, 'waiting')->count(), self::TYPES)),
            'requests' => $this->unseenDecisions($project, $user)->count(),
        ];
    }

    /** Opening My requests: the decisions shown there count as seen. */
    public function markDecisionsSeen(Project $project, User $user): void
    {
        $ids = $this->unseenDecisions($project, $user)->pluck('id');
        if ($ids->isNotEmpty()) {
            $user->unreadNotifications()->whereIn('id', $ids)->update(['read_at' => now()]);
        }
    }

    private function unseenDecisions(Project $project, User $user): Collection
    {
        return $user->unreadNotifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === 'budget_request'
            && in_array($n->data['event'] ?? null, ['approved', 'returned', 'rejected'], true)
            && (int) ($n->data['project_id'] ?? 0) === (int) $project->id)->values();
    }

    /** One record of the project, whatever its stage (for the side panel). */
    public function show(Project $project, User $user, string $type, int $id): array
    {
        $record = $this->model($type)::query()->where('project_id', $project->id)->findOrFail($id);

        return $this->item($type, $record, $user);
    }

    private function model(string $type): string
    {
        return [
            'expense' => Expense::class,
            'payroll' => PayrollBatch::class,
            'purchase_request' => PurchaseRequest::class,
        ][$type];
    }

    private function query(Project $project, User $user, string $type, string $tab)
    {
        $query = $this->model($type)::query()->where('project_id', $project->id);

        return match ($tab) {
            // Waiting for this Project Leader: never their own expenses or
            // purchase requests (the maker never approves; those skip the step).
            'waiting' => $this->waitingForLeader($query, $type, $user),
            'returned' => $query->where('workflow_status', 'returned'),
            'approved' => $query->where('pl_reviewed_by', $user->id)->whereIn('workflow_status', self::AFTER_APPROVAL[$type]),
        };
    }

    private function waitingForLeader($query, string $type, User $user)
    {
        $query->where('workflow_status', 'submitted');

        return match ($type) {
            'expense' => $query->where('recorded_by', '!=', $user->id),
            'purchase_request' => $query->where('requested_by', '!=', $user->id),
            // Payroll stays listed with its waiting text (e.g. "Waiting for Program Leader").
            'payroll' => $query,
        };
    }

    /** One queue row; the same shape for every record type. */
    private function item(string $type, $record, User $user): array
    {
        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;
        $base = [
            'key' => $type . '-' . $record->id,
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type],
            'id' => $record->id,
            'project_id' => $record->project_id,
            'workflow_status' => $record->workflow_status,
            'return_reason' => $record->return_reason,
            'submitted_at' => $record->submitted_at?->toIso8601String(),
            'reviewed_by' => $person($record->plReviewedBy),
            'reviewed_at' => $record->pl_reviewed_at?->toIso8601String(),
            'decided_at' => ($record->pl_reviewed_at ?? $record->updated_at)?->toIso8601String(),
            'documents_count' => $record->documents()->count(),
        ];

        return $base + match ($type) {
            'expense' => $this->expenseItem($record, $user, $person),
            'payroll' => $this->payrollItem($record, $user, $person),
            'purchase_request' => $this->purchaseRequestItem($record, $user, $person),
        };
    }

    private function expenseItem(Expense $expense, User $user, callable $person): array
    {
        $expense->loadMissing(['libCategory', 'recordedBy', 'verifiedBy']);
        $mine = (int) $expense->recorded_by === (int) $user->id;

        return [
            'title' => $expense->description,
            'details' => trim(implode(' · ', array_filter([
                $expense->libCategory?->line_item_name,
                $expense->payee,
                $expense->reference_no ? 'Ref. ' . $expense->reference_no : null,
            ]))),
            'line_item' => $expense->libCategory?->line_item_name,
            'category' => $expense->libCategory?->category_type,
            'amount' => round((float) $expense->amount, 2),
            'workflow_label' => ExpenseWorkflowService::label($expense),
            'submitted_by' => $person($expense->recordedBy),
            'fields' => [
                ['label' => 'Line item', 'value' => $expense->libCategory?->line_item_name],
                ['label' => 'Expense date', 'value' => $expense->expense_date?->toDateString(), 'kind' => 'date'],
                ['label' => 'Payee', 'value' => $expense->payee],
                ['label' => 'Reference no.', 'value' => $expense->reference_no],
                ['label' => 'Receipts', 'value' => (string) $expense->receipts()->count()],
                ['label' => 'Verified by', 'value' => $expense->verifiedBy?->name],
            ],
            'can_review' => $expense->workflow_status === 'submitted' && ! $mine,
            'waiting_text' => $expense->workflow_status === 'submitted' && $mine ? 'You recorded this expense, so it skips your approval and goes to Finance/Admin.' : null,
            // Edit and resubmit: only the Project Leader who recorded it, while returned
            // (or a draft); anyone else's expense stays read-only here.
            'can_edit' => $mine && in_array($expense->workflow_status, ['draft', 'returned'], true),
            'edit' => $mine ? [
                'lib_category_id' => $expense->lib_category_id,
                'funding_source' => $expense->funding_source ?: 'funder',
                'description' => $expense->description,
                'amount' => round((float) $expense->amount, 2),
                'expense_date' => $expense->expense_date?->toDateString(),
                'payee' => $expense->payee,
                'reference_no' => $expense->reference_no,
            ] : null,
        ];
    }

    private function payrollItem(PayrollBatch $batch, User $user, callable $person): array
    {
        $batch->loadMissing(['submittedBy', 'preparedBy', 'verifiedBy', 'rows']);
        $rows = $batch->rows;
        $canApprove = $this->payroll->canApprove($batch, $user);

        return [
            'title' => 'Payroll ' . $batch->monthLabel(),
            'details' => $rows->count() . ' ' . ($rows->count() === 1 ? 'person' : 'people') . ' · net ₱' . number_format((float) $rows->sum('net_pay'), 2),
            'line_item' => null,
            'category' => 'PS',
            'month' => $batch->monthKey(),
            'amount' => round((float) $rows->sum('gross_pay'), 2),
            'workflow_label' => PayrollService::LABELS[$batch->workflow_status] ?? $batch->workflow_status,
            'submitted_by' => $person($batch->submittedBy ?? $batch->preparedBy),
            'fields' => [
                ['label' => 'Pay period', 'value' => $batch->monthLabel()],
                ['label' => 'People', 'value' => (string) $rows->count()],
                ['label' => 'Gross pay', 'value' => round((float) $rows->sum('gross_pay'), 2), 'kind' => 'money'],
                ['label' => 'Net pay', 'value' => round((float) $rows->sum('net_pay'), 2), 'kind' => 'money'],
                ['label' => 'Verified by', 'value' => $batch->verifiedBy?->name],
            ],
            'can_review' => $canApprove,
            'waiting_text' => $canApprove ? null : $this->payroll->waitingText($batch, $user),
            'can_edit' => false,
            'edit' => null,
        ];
    }

    private function purchaseRequestItem(PurchaseRequest $request, User $user, callable $person): array
    {
        $request->loadMissing(['libCategory', 'requestedBy', 'expenses', 'items.libCategory']);
        $mine = (int) $request->requested_by === (int) $user->id;
        $items = PurchaseRequestItems::rows($request);
        $count = count($items);
        $types = collect($items)->pluck('line_item_type')->filter()->unique()->values();

        return [
            'title' => $request->description,
            'details' => trim(implode(' · ', array_filter([
                PurchaseRequestItems::lineSummary($request),
                $count . ' ' . ($count === 1 ? 'item' : 'items'),
            ]))),
            'line_item' => PurchaseRequestItems::lineSummary($request),
            'category' => $types->count() === 1 ? $types->first() : ($types->isEmpty() ? $request->libCategory?->category_type : $types->implode(' + ')),
            'pr_items' => $items,
            'pr_total' => round((float) $request->estimated_amount, 2),
            'amount' => round((float) $request->estimated_amount, 2),
            'workflow_label' => PurchaseRequestService::label($request),
            'fulfilment' => $request->fulfilment(),
            'submitted_by' => $person($request->requestedBy),
            'fields' => array_merge([
                ['label' => 'Purpose / remarks', 'value' => $request->description],
                ['label' => 'Total', 'value' => round((float) $request->estimated_amount, 2), 'kind' => 'money'],
                ['label' => 'Supplier / payee', 'value' => $request->supplier],
            ], PurchaseRequestItems::numberFields($request), [
                ['label' => 'Cancel reason', 'value' => $request->cancel_reason],
            ]),
            'can_review' => $request->workflow_status === 'submitted' && ! $mine,
            'waiting_text' => $request->workflow_status === 'submitted' && $mine ? 'You made this request, so it skips your approval.' : null,
            // Approved or partly fulfilled (open items left): the Project Leader
            // may cancel it with a reason (PurchaseRequestService::cancel rules).
            'can_cancel' => $request->workflow_status === PurchaseRequest::OPEN_APPROVED
                && $request->project && $user->hasRoleOn($request->project, 'project-leader'),
            'can_edit' => false,
            'edit' => null,
        ];
    }

    // ---------------------------------------------------------------- overview

    public function overview(Project $project, User $user): array
    {
        $project->loadMissing(['fundingAgency', 'libCategories', 'tranches']);
        $metrics = $this->financials->financials($project);
        $queue = $this->queue($project, $user, 'waiting');
        $tranches = $this->dashboard->trancheRows($project);
        $compliance = $this->financials->compliance($project);
        $requests = $this->budgetRequests($project);

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
            ],
            'kpis' => [
                'total_budget' => $metrics['budget'],
                // Sums of the line items (no separately typed figure).
                'funder_share' => $this->financials->fundingTotals($project)['funder'],
                'counterpart' => $this->financials->fundingTotals($project)['implementing_agency'],
                'total_spent' => $metrics['spent'],
                'remaining' => $metrics['remaining'],
                'utilization_pct' => $metrics['utilization_pct'],
                'to_review' => $queue['counts']['waiting'],
                'to_review_actionable' => collect($queue['items'])->where('can_review', true)->count(),
            ],
            'review' => array_slice($queue['items'], 0, 5),
            'alerts' => [
                'burn_rate' => $this->dashboard->burnRate($project, $metrics['budget'], $metrics['spent']),
                'next_tranche' => $this->nextTranche($tranches['items'], $requests),
                // 0 = no tranches set yet (next_tranche null then does not mean "all released").
                'tranche_count' => count($tranches['items']),
                // 0 = the Program Leader has not set up the line-item budget yet.
                'line_item_count' => $project->libCategories->count(),
                'missing_receipts' => [
                    'count' => $compliance['missing_receipt'],
                    'items' => array_slice($compliance['missing_items'], 0, 3),
                ],
            ],
            'monthly_expenses' => $this->program->monthlyExpenses(collect([$project]), now()->startOfDay()),
            'category_summary' => $this->program->categorySummary(collect([$project]), $metrics['budget']),
            'transactions' => $this->spentRows($project)->take(5)->values()->all(),
            'requests' => $requests->take(5)->values()->all(),
        ];
    }

    /**
     * The first unreleased release (by planned date), with whether a fund release
     * for it is already pending.
     */
    private function nextTranche(array $tranches, Collection $requests): ?array
    {
        // Rows are in planned-date order (DashboardService::trancheRows).
        $next = collect($tranches)->where('is_released', false)->first();
        if (! $next) {
            return null;
        }
        $pending = $requests->first(fn ($r) => $r['type'] === 'fund_release' && (int) $r['project_tranche_id'] === (int) $next['id'] && $r['status'] === 'pending');

        return $next + ['requested' => (bool) $pending, 'request_id' => $pending['id'] ?? null];
    }

    /** This project's budget requests (same rows as GET budget-requests), newest first. */
    private function budgetRequests(Project $project): Collection
    {
        return $project->approvalRequests()
            ->whereIn('type', array_values(BudgetRequestService::TYPES))
            ->with(['reviewedBy', 'tranche'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApprovalRequest $approval) => [
                'id' => $approval->id,
                'type' => array_search($approval->type, BudgetRequestService::TYPES, true) ?: $approval->type,
                'type_label' => $approval->typeLabel(),
                'title' => $approval->displayTitle(),
                'amount' => $approval->amount !== null ? (float) $approval->amount : null,
                'project_tranche_id' => $approval->project_tranche_id,
                // pending | approved | returned | withdrawn
                'status' => $approval->displayStatus(),
                'status_label' => $approval->statusLabel(),
                'reviewed_by' => $approval->reviewedBy?->name ?? $approval->reviewed_by_name,
                'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
                'review_notes' => $approval->review_notes,
                'created_at' => $approval->created_at?->toIso8601String(),
            ]);
    }

    // ---------------------------------------------------------------- transactions

    /**
     * Spent transactions of the project, newest first, 25 per page: verified
     * expenses and verified payroll (one row per batch and line item), the
     * same records Total spent adds up.
     *
     * @param array{from?: string, to?: string, line_item?: int, category?: string, page?: int} $filters
     */
    public function transactions(Project $project, array $filters): array
    {
        $all = $this->spentRows($project, $filters);

        $total = $all->count();
        $amount = round((float) $all->sum('amount'), 2);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);

        return [
            'transactions' => $all->forPage($page, self::PER_PAGE)->values()->all(),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total, 'last_page' => $lastPage, 'amount' => $amount],
            'line_items' => $project->libCategories()->orderBy('category_type')->orderBy('id')->get()
                ->map(fn ($item) => ['id' => $item->id, 'name' => $item->line_item_name, 'type' => $item->category_type])->values()->all(),
        ];
    }

    /**
     * Every spent row (transactionRows() shape, plus `kind` expense / payroll),
     * newest first, with the Transactions filters applied.
     *
     * receipt=missing keeps only verified expenses without a receipt (the
     * "Missing receipts" KPI); payroll has no receipts, so it is left out.
     *
     * @param array{from?: string, to?: string, line_item?: int, category?: string, receipt?: string} $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function spentRows(Project $project, array $filters = [])
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $lineItem = $filters['line_item'] ?? null;
        $type = $filters['category'] ?? null;
        $missingReceipt = ($filters['receipt'] ?? null) === 'missing';

        $expenses = $this->spentQuery($project)
            ->when($missingReceipt, fn ($q) => ProjectFinancialService::missingReceipt($q))
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->when($lineItem, fn ($q) => $q->where('lib_category_id', $lineItem))
            ->when($type, fn ($q) => $q->whereHas('libCategory', fn ($c) => $c->where('category_type', $type)))
            ->get();

        $payroll = ($missingReceipt ? collect() : ProjectFinancialService::payrollEntries([(int) $project->id]))
            ->filter(fn (array $p) => (! $from || $p['date'] >= $from) && (! $to || $p['date'] <= $to)
                && (! $lineItem || (int) $p['lib_category_id'] === (int) $lineItem)
                && (! $type || $p['type'] === $type))
            ->map(fn (array $p) => [
                'kind' => 'payroll',
                'id' => $p['key'],
                'batch_id' => $p['batch_id'],
                'date' => $p['date'],
                'description' => $p['description'],
                'payee' => null,
                'reference_no' => $p['reference_no'],
                'line_item' => $p['line_item'],
                'category' => $p['type'],
                'amount' => $p['amount'],
                'receipts_count' => null,
                'documents_count' => $p['batch_id'] ? Document::query()->where('documentable_type', (new PayrollBatch())->getMorphClass())->where('documentable_id', $p['batch_id'])->count() : 0,
                'recorded_by' => $p['recorded_by'],
                'verified_by' => $p['verified_by'],
                'verified_at' => $p['verified_at'],
            ]);

        return collect($this->transactionRows($expenses))->concat($payroll)
            ->sortBy([['date', 'desc'], ['verified_at', 'desc']])
            ->values();
    }

    public function spentQuery(Project $project)
    {
        return ProjectFinancialService::onlySpent($project->expenses())
            ->with(['libCategory', 'recordedBy', 'verifiedBy'])
            ->withCount(['receipts', 'documents'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');
    }

    public function transactionRows($expenses): array
    {
        return collect($expenses)->map(fn (Expense $expense) => [
            'kind' => 'expense',
            'id' => $expense->id,
            'date' => $expense->expense_date?->toDateString(),
            'description' => $expense->description,
            'payee' => $expense->payee,
            'reference_no' => $expense->reference_no,
            'line_item' => $expense->libCategory?->line_item_name,
            'category' => $expense->libCategory?->category_type,
            'amount' => round((float) $expense->amount, 2),
            'receipts_count' => (int) $expense->receipts_count,
            'documents_count' => (int) $expense->documents_count,
            'recorded_by' => $expense->recordedBy?->name,
            'verified_by' => $expense->verifiedBy?->name,
            'verified_at' => $expense->verified_at?->toIso8601String(),
        ])->values()->all();
    }
}

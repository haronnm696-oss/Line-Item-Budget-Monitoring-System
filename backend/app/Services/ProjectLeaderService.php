<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\PurchaseRequest;
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
            && in_array($n->data['event'] ?? null, ['approved', 'rejected'], true)
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
            'waiting' => $query->where('workflow_status', 'submitted'),
            'returned' => $query->where('workflow_status', 'returned'),
            'approved' => $query->where('pl_reviewed_by', $user->id)->whereIn('workflow_status', self::AFTER_APPROVAL[$type]),
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
            'waiting_text' => $expense->workflow_status === 'submitted' && $mine ? 'You recorded this expense; another Project Leader must approve it.' : null,
            // Edit and resubmit: only the Project Leader who recorded it, while returned
            // (or a draft); anyone else's expense stays read-only here.
            'can_edit' => $mine && in_array($expense->workflow_status, ['draft', 'returned'], true),
            'edit' => $mine ? [
                'lib_category_id' => $expense->lib_category_id,
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
                ['label' => 'Month', 'value' => $batch->monthLabel()],
                ['label' => 'People', 'value' => (string) $rows->count()],
                ['label' => 'Gross pay', 'value' => round((float) $rows->sum('gross_pay'), 2), 'kind' => 'money'],
                ['label' => 'Deductions', 'value' => round((float) $rows->sum('total_deductions'), 2), 'kind' => 'money'],
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
        $request->loadMissing(['libCategory', 'requestedBy']);
        $mine = (int) $request->requested_by === (int) $user->id;

        return [
            'title' => $request->description,
            'details' => trim(implode(' · ', array_filter([
                $request->libCategory?->line_item_name,
                'Qty ' . $request->quantity,
            ]))),
            'line_item' => $request->libCategory?->line_item_name,
            'category' => $request->libCategory?->category_type,
            'amount' => round((float) $request->estimated_amount, 2),
            'workflow_label' => PurchaseRequestService::LABELS[$request->workflow_status] ?? $request->workflow_status,
            'submitted_by' => $person($request->requestedBy),
            'fields' => [
                ['label' => 'Line item', 'value' => $request->libCategory?->line_item_name],
                ['label' => 'Quantity', 'value' => (string) $request->quantity],
                ['label' => 'Estimated amount', 'value' => round((float) $request->estimated_amount, 2), 'kind' => 'money'],
                ['label' => 'Cancel reason', 'value' => $request->cancel_reason],
            ],
            'can_review' => $request->workflow_status === 'submitted' && ! $mine,
            'waiting_text' => $request->workflow_status === 'submitted' && $mine ? 'You made this request; another Project Leader must approve it.' : null,
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
                'status' => ucfirst($this->financials->derivedStatus($project)),
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
            ],
            'kpis' => [
                'total_budget' => $metrics['budget'],
                'funder_share' => round((float) $project->funding_agency_amount, 2),
                'counterpart' => round((float) $project->implementing_agency_counterpart, 2),
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
                'missing_receipts' => [
                    'count' => $compliance['missing_receipt'],
                    'items' => array_slice($compliance['missing_items'], 0, 3),
                ],
            ],
            'monthly_expenses' => $this->program->monthlyExpenses(collect([$project]), now()->startOfDay()),
            'category_summary' => $this->program->categorySummary(collect([$project]), $metrics['budget']),
            'transactions' => $this->transactionRows($this->spentQuery($project)->limit(5)->get()),
            'requests' => $requests->take(5)->values()->all(),
        ];
    }

    /**
     * The first unreleased tranche (by sequence), with whether a fund release
     * for it is already pending.
     */
    private function nextTranche(array $tranches, Collection $requests): ?array
    {
        $next = collect($tranches)->where('is_released', false)->sortBy('sequence')->first();
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
            ->with(['reviewedBy'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApprovalRequest $approval) => [
                'id' => $approval->id,
                'type' => array_search($approval->type, BudgetRequestService::TYPES, true) ?: $approval->type,
                'type_label' => $approval->typeLabel(),
                'title' => $approval->title,
                'amount' => $approval->amount !== null ? (float) $approval->amount : null,
                'project_tranche_id' => $approval->project_tranche_id,
                'status' => $approval->status,
                'reviewed_by' => $approval->reviewedBy?->name ?? $approval->reviewed_by_name,
                'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
                'review_notes' => $approval->review_notes,
                'created_at' => $approval->created_at?->toIso8601String(),
            ]);
    }

    // ---------------------------------------------------------------- transactions

    /**
     * Verified (spent) expenses of the project, newest first, 25 per page.
     *
     * @param array{from?: string, to?: string, line_item?: int, category?: string, page?: int} $filters
     */
    public function transactions(Project $project, array $filters): array
    {
        $query = $this->spentQuery($project)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('expense_date', '<=', $to))
            ->when($filters['line_item'] ?? null, fn ($q, $id) => $q->where('lib_category_id', $id))
            ->when($filters['category'] ?? null, fn ($q, $type) => $q->whereHas('libCategory', fn ($c) => $c->where('category_type', $type)));

        $total = (clone $query)->count();
        $amount = round((float) (clone $query)->sum('amount'), 2);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $rows = $query->forPage($page, self::PER_PAGE)->get();

        return [
            'transactions' => $this->transactionRows($rows),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total, 'last_page' => $lastPage, 'amount' => $amount],
            'line_items' => $project->libCategories()->orderBy('category_type')->orderBy('id')->get()
                ->map(fn ($item) => ['id' => $item->id, 'name' => $item->line_item_name, 'type' => $item->category_type])->values()->all(),
        ];
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

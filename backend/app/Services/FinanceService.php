<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\Concerns\RunsWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Finance/Admin views (Overview, Verification queue, Payroll list). Verify
 * and reject use the existing workflow endpoints; every figure comes from
 * ProjectFinancialService and the workflow services.
 *
 * Queue tabs (one project):
 *   waiting   expenses and payroll batches approved by the Project Leader
 *             (or the Program Leader) and not verified yet: status "endorsed".
 *             Listed even when this user may not verify (the row says why).
 *   verified  records this user verified
 *   rejected  records this user rejected (their latest reject audit entry)
 * "To verify" on the Overview and the sidebar badge = the waiting tab count.
 */
class FinanceService
{
    use RunsWorkflow;

    public const TYPES = ['expense', 'payroll'];
    public const TABS = ['waiting', 'verified', 'rejected'];

    public const PAID_IN_BATCH = 'You are paid in this batch. Another verifier is needed.';

    private const MODEL = ['expense' => Expense::class, 'payroll' => PayrollBatch::class];
    private const AUDIT_MODEL = ['expense' => 'Expense', 'payroll' => 'PayrollBatch'];

    public function __construct(
        private ProjectFinancialService $financials,
        private PayrollService $payroll
    ) {
    }

    public function requireFinance(Project $project, User $user): void
    {
        abort_unless($user->hasRoleOn($project, 'finance-admin'), 403, 'Only Finance/Admin staff of this project can open this page.');
    }

    // ---------------------------------------------------------------- queue

    public function count(Project $project, User $user, string $tab, ?string $type = null): int
    {
        return array_sum(array_map(fn ($t) => $this->query($project, $user, $t, $tab)->count(), $type ? [$type] : self::TYPES));
    }

    public function badges(Project $project, User $user): array
    {
        return ['verification' => $this->count($project, $user, 'waiting')];
    }

    /** @return array{counts: array<string, int>, items: array<int, array<string, mixed>>, late_receipts: array} */
    public function queue(Project $project, User $user, string $tab = 'waiting', ?string $type = null): array
    {
        $counts = [];
        foreach (self::TABS as $name) {
            $counts[$name] = $this->count($project, $user, $name, $type);
        }

        $items = collect($type ? [$type] : self::TYPES)
            ->flatMap(fn ($t) => $this->query($project, $user, $t, $tab)->get()->map(fn ($record) => $this->item($t, $record, $user)))
            ->values();

        // Waiting: oldest approval first. Verified / rejected: newest decision first.
        $items = $tab === 'waiting'
            ? $items->sortBy(fn ($item) => [$item['approved_at'] ?? $item['submitted_at'] ?? '', $item['key']])
            : $items->sortByDesc(fn ($item) => [$item['decided_at'] ?? '', $item['key']]);

        return [
            'counts' => $counts,
            'items' => $items->values()->all(),
            'late_receipts' => $type === 'payroll' ? [] : $this->lateReceipts($project),
        ];
    }

    public function show(Project $project, User $user, string $type, int $id): array
    {
        $record = self::MODEL[$type]::query()->where('project_id', $project->id)->findOrFail($id);

        return $this->item($type, $record, $user, true);
    }

    private function query(Project $project, User $user, string $type, string $tab): Builder
    {
        $query = self::MODEL[$type]::query()->where('project_id', $project->id);

        return match ($tab) {
            'waiting' => $query->where('workflow_status', 'endorsed'),
            'verified' => $query->where('workflow_status', 'verified')->where('verified_by', $user->id),
            'rejected' => $query->whereIn('id', AuditTrail::query()
                ->where('project_id', $project->id)->where('user_id', $user->id)
                ->where('action', 'rejected')->where('model_type', self::AUDIT_MODEL[$type])
                ->select('model_id')),
        };
    }

    // ---------------------------------------------------------------- overview

    public function overview(Project $project, User $user): array
    {
        $metrics = $this->financials->financials($project);
        $compliance = $this->financials->compliance($project);
        $queue = $this->queue($project, $user, 'waiting');
        [$start, $end] = $this->thisMonth();

        $verifiedExpenses = ProjectFinancialService::onlySpent($project->expenses())->whereBetween('verified_at', [$start, $end]);
        $verifiedBatches = PayrollBatch::query()->where('project_id', $project->id)
            ->where('workflow_status', 'verified')->whereBetween('verified_at', [$start, $end]);

        // Transactions this user verified, newest first.
        $recent = collect()
            ->merge(ProjectFinancialService::onlySpent($project->expenses())->where('verified_by', $user->id)
                ->orderByDesc('verified_at')->limit(5)->get()->map(fn ($e) => $this->item('expense', $e, $user)))
            ->merge(PayrollBatch::query()->where('project_id', $project->id)->where('workflow_status', 'verified')->where('verified_by', $user->id)
                ->orderByDesc('verified_at')->limit(5)->get()->map(fn ($b) => $this->item('payroll', $b, $user)))
            ->sortByDesc(fn ($item) => $item['verified_at'] ?? '')
            ->take(5)->values()->all();

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'title' => $project->title,
                'project_leader' => $project->usersWithRole('project-leader')->orderBy('name')->pluck('name')->join(', ') ?: null,
            ],
            'kpis' => [
                'to_verify' => $queue['counts']['waiting'],
                'to_verify_actionable' => collect($queue['items'])->where('can_verify', true)->count(),
                // Expense amount verified this month (payroll is not spent), and the
                // counts of expenses and payroll batches verified, kept separate.
                'verified_this_month' => round((float) (clone $verifiedExpenses)->sum('amount'), 2),
                'verified_this_month_expenses' => (clone $verifiedExpenses)->count(),
                'verified_this_month_payroll' => (clone $verifiedBatches)->count(),
                'month_label' => Carbon::now('Asia/Manila')->format('F Y'),
                'missing_receipts' => $compliance['missing_receipt'],
                'receipt_compliance_pct' => $compliance['compliance_pct'],
                'verified_with_receipt' => $compliance['verified_with_receipt'],
                'verified_expenses' => $compliance['verified'],
                'total_spent' => $metrics['spent'],
                'total_budget' => $metrics['budget'],
                'utilization_pct' => $metrics['utilization_pct'],
            ],
            'queue' => array_slice($queue['items'], 0, 6),
            'missing_receipts' => $compliance['missing_items'],
            'recently_verified' => $recent,
            'late_receipts' => array_slice($queue['late_receipts'], 0, 5),
        ];
    }

    /** Every payroll batch of the project, newest period first, with its next step. */
    public function payrollBatches(Project $project, User $user): array
    {
        return PayrollBatch::query()->where('project_id', $project->id)
            ->orderByDesc('pay_year')->orderByDesc('pay_month')
            ->get()
            ->map(fn (PayrollBatch $batch) => $this->item('payroll', $batch, $user))
            ->values()->all();
    }

    // ---------------------------------------------------------------- receipts

    /**
     * Receipts added to an expense after it was verified (late receipts).
     * Information only: the expense is not verified again.
     */
    public function lateReceipts(Project $project): array
    {
        $expenses = ProjectFinancialService::onlySpent($project->expenses())->whereNotNull('verified_at')->get()->keyBy('id');
        if ($expenses->isEmpty()) {
            return [];
        }

        return Document::query()
            ->with('uploader')
            ->where('documentable_type', (new Expense())->getMorphClass())
            ->whereIn('documentable_id', $expenses->keys())
            ->where('document_type', Document::TYPE_RECEIPT)
            ->orderByDesc('uploaded_at')->orderByDesc('id')
            ->get()
            ->filter(fn (Document $doc) => $doc->uploaded_at && $doc->uploaded_at->greaterThan($expenses[$doc->documentable_id]->verified_at))
            ->take(10)
            ->map(function (Document $doc) use ($expenses) {
                $expense = $expenses[$doc->documentable_id];

                return [
                    'document_id' => $doc->id,
                    'expense_id' => $expense->id,
                    'key' => 'expense-' . $expense->id,
                    'reference' => $expense->reference_no ?: 'EXP-' . $expense->id,
                    'title' => $expense->description,
                    'amount' => round((float) $expense->amount, 2),
                    'uploaded_by' => $doc->uploader?->name,
                    'uploaded_at' => $doc->uploaded_at?->toIso8601String(),
                    'verified_at' => $expense->verified_at?->toIso8601String(),
                ];
            })->values()->all();
    }

    /**
     * "Notify RA": ask for the missing receipt of a verified expense. The
     * person who recorded it and the project's Research Assistants are
     * notified; one audit entry.
     */
    public function requestReceipt(Expense $expense, User $actor): int
    {
        $project = $expense->project;
        $this->requireFinance($project, $actor);
        if (! $expense->awaitsLateReceipt()) {
            throw ValidationException::withMessages(['expense' => ['Only a verified expense without a receipt can be asked for one.']]);
        }

        $recipients = $this->activeUser($expense->recorded_by)
            ->merge($this->projectMembers($project->id, 'research-assistant'))
            ->unique('id')
            ->reject(fn (User $user) => (int) $user->id === (int) $actor->id)
            ->values();

        AuditLogger::transaction(fn () => AuditLogger::write('receipt_requested', $expense, [], [
            'notified' => $recipients->pluck('id')->all(),
        ], $actor));

        $what = trim(implode(' · ', array_filter([$expense->reference_no, $expense->description])));
        $this->notifyUsers($recipients, new WorkflowNotification([
            'kind' => 'expense',
            'event' => 'receipt_requested',
            'title' => 'Receipt needed',
            'message' => "{$actor->name} (Finance/Admin) asks for the receipt of a verified expense: {$what} (₱" . number_format((float) $expense->amount, 2) . ').',
            'expense_id' => $expense->id,
            'project_id' => $expense->project_id,
            'project' => $project->title,
            'actor' => $actor->name,
            'reason' => null,
            'link' => '/expenses?project=' . $expense->project_id . '&open=expense-' . $expense->id,
        ]), $actor);

        return $recipients->count();
    }

    // ---------------------------------------------------------------- rows

    private function item(string $type, $record, User $user, bool $full = false): array
    {
        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;
        $item = [
            'key' => $type . '-' . $record->id,
            'type' => $type,
            'type_label' => $type === 'expense' ? 'Expense' : 'Payroll',
            'id' => $record->id,
            'project_id' => (int) $record->project_id,
            'workflow_status' => $record->workflow_status,
            'return_reason' => $record->workflow_status === 'returned' ? $record->return_reason : null,
            'submitted_at' => $record->submitted_at?->toIso8601String(),
            'approved_by' => $person($record->plReviewedBy),
            'approved_at' => $record->pl_reviewed_at?->toIso8601String(),
            'verified_by' => $record->workflow_status === 'verified' ? $person($record->verifiedBy) : null,
            'verified_at' => $record->verified_at?->toIso8601String(),
            'decided_at' => ($record->verified_at ?? $record->updated_at)?->toIso8601String(),
            'documents_count' => $record->documents()->count(),
        ] + ($type === 'expense' ? $this->expenseItem($record, $user) : $this->payrollItem($record, $user));

        if (! $full) {
            unset($item['fields']);
        }

        return $item;
    }

    private function expenseItem(Expense $expense, User $user): array
    {
        $expense->loadMissing(['libCategory', 'recordedBy', 'plReviewedBy', 'verifiedBy']);
        $endorsed = $expense->workflow_status === 'endorsed';
        $blocked = null;
        if ($endorsed && (int) $expense->recorded_by === (int) $user->id) {
            $blocked = 'You recorded this expense; another Finance/Admin must verify it.';
        } elseif ($endorsed && (int) $expense->pl_reviewed_by === (int) $user->id) {
            $blocked = 'You approved this expense as Project Leader; another person must verify it.';
        }
        $receipts = $expense->receipts()->count();
        $mine = (int) $expense->recorded_by === (int) $user->id && in_array($expense->workflow_status, ['draft', 'returned'], true);

        return [
            'reference' => $expense->reference_no ?: 'EXP-' . $expense->id,
            'title' => $expense->description,
            'details' => trim(implode(' · ', array_filter([$expense->libCategory?->line_item_name, $expense->payee]))),
            'line_item' => $expense->libCategory?->line_item_name,
            'date' => $expense->expense_date?->toDateString(),
            'category' => $expense->libCategory?->category_type,
            'amount' => round((float) $expense->amount, 2),
            'workflow_label' => ExpenseWorkflowService::label($expense),
            'submitted_by' => ['id' => $expense->recordedBy?->id, 'name' => $expense->recordedBy?->name],
            'approval_note' => $expense->pl_reviewed_by === null && $expense->workflow_status !== 'draft'
                ? 'Recorded by the Project Leader (no approval step)' : null,
            'receipts_count' => $receipts,
            'can_verify' => $endorsed && $blocked === null && $user->hasRoleOn($expense->project, 'finance-admin'),
            'blocked_text' => $blocked,
            'counts_as_spent' => true,
            // An expense this Finance/Admin recorded: edit and resubmit while draft / returned.
            'can_edit' => $mine,
            'edit' => $mine ? [
                'lib_category_id' => $expense->lib_category_id,
                'description' => $expense->description,
                'amount' => round((float) $expense->amount, 2),
                'expense_date' => $expense->expense_date?->toDateString(),
                'payee' => $expense->payee,
                'reference_no' => $expense->reference_no,
                'purchase_request_id' => $expense->purchase_request_id,
            ] : null,
            'fields' => [
                ['label' => 'Line item', 'value' => $expense->libCategory?->line_item_name],
                ['label' => 'Category', 'value' => $expense->libCategory?->category_type],
                ['label' => 'Expense date', 'value' => $expense->expense_date?->toDateString(), 'kind' => 'date'],
                ['label' => 'Payee', 'value' => $expense->payee],
                ['label' => 'Reference no.', 'value' => $expense->reference_no],
                ['label' => 'Receipts', 'value' => (string) $receipts],
                ['label' => 'Approved by', 'value' => $expense->plReviewedBy?->name],
                ['label' => 'Verified by', 'value' => $expense->workflow_status === 'verified' ? $expense->verifiedBy?->name : null],
            ],
        ];
    }

    private function payrollItem(PayrollBatch $batch, User $user): array
    {
        $batch->loadMissing(['rows', 'plReviewedBy', 'verifiedBy', 'preparedBy', 'submittedBy']);
        $rows = $batch->rows;
        $endorsed = $batch->workflow_status === 'endorsed';
        $canVerify = $this->payroll->canVerify($batch, $user);
        $blocked = null;
        if ($endorsed && ! $canVerify) {
            if ($batch->pendingEscalation() !== null || $this->payroll->isVerificationBlocked($batch)) {
                $blocked = $this->payroll->waitingText($batch, $user);
            } elseif (in_array((int) $user->id, $batch->paidUserIds(), true)) {
                $blocked = self::PAID_IN_BATCH;
            } elseif (in_array((int) $user->id, $batch->makerIds(), true)) {
                $blocked = 'You prepared this payroll; another Finance/Admin must verify it.';
            } elseif ((int) $batch->pl_reviewed_by === (int) $user->id) {
                $blocked = 'You approved this payroll; another person must verify it.';
            } else {
                $blocked = $this->payroll->waitingText($batch, $user);
            }
        }
        $preparer = $batch->submittedBy ?? $batch->preparedBy;

        return [
            'reference' => $batch->reference(),
            'title' => 'Payroll ' . $batch->monthLabel(),
            'month' => $batch->monthKey(),
            'details' => $rows->count() . ' ' . ($rows->count() === 1 ? 'person' : 'people') . ' · net ₱' . number_format((float) $rows->sum('net_pay'), 2),
            'category' => 'PS',
            'amount' => round((float) $rows->sum('gross_pay'), 2),
            'workflow_label' => PayrollService::LABELS[$batch->workflow_status] ?? $batch->workflow_status,
            'next_step' => match ($batch->workflow_status) {
                'draft' => 'Being prepared',
                'returned' => 'Returned to the preparer',
                'verified' => 'Verified (payroll is not part of LIB spent)',
                default => $this->payroll->waitingText($batch, $user),
            },
            'submitted_by' => ['id' => $preparer?->id, 'name' => $preparer?->name],
            'approval_note' => null,
            'can_verify' => $canVerify,
            'blocked_text' => $blocked,
            'counts_as_spent' => false,
            'can_edit' => false,
            'edit' => null,
            'fields' => [
                ['label' => 'Pay period', 'value' => $batch->monthLabel()],
                ['label' => 'People', 'value' => (string) $rows->count()],
                ['label' => 'Gross pay', 'value' => round((float) $rows->sum('gross_pay'), 2), 'kind' => 'money'],
                ['label' => 'Net pay', 'value' => round((float) $rows->sum('net_pay'), 2), 'kind' => 'money'],
                ['label' => 'Approved by', 'value' => $batch->plReviewedBy?->name],
                ['label' => 'Verified by', 'value' => $batch->workflow_status === 'verified' ? $batch->verifiedBy?->name : null],
            ],
        ];
    }

    /** First and last moment of the current month in Manila, as stored (app timezone). */
    private function thisMonth(): array
    {
        $now = Carbon::now('Asia/Manila');

        return [
            $now->copy()->startOfMonth()->setTimezone(config('app.timezone')),
            $now->copy()->endOfMonth()->setTimezone(config('app.timezone')),
        ];
    }

    protected function stageLabels(): array
    {
        return ExpenseWorkflowService::LABELS;
    }

    protected function noun(): string
    {
        return 'record';
    }
}

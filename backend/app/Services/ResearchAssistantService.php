<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only views for the Research Assistant pages (Overview, My
 * submissions, Documents). Every change goes through the existing workflow
 * endpoints (expenses, purchase requests, payroll batches, documents).
 * Figures come from ProjectFinancialService (receipt compliance) and the
 * workflow services' labels; nothing is computed here.
 *
 * "Mine" = expenses the user recorded, purchase requests the user made and
 * payroll batches the user prepared or submitted.
 *
 * Tabs (one project, the user's own records):
 *   drafts    draft
 *   waiting   submitted (the same records the Project Leader sees as "To review")
 *   returned  returned (by the Project Leader or rejected by Finance/Admin)
 *   approved  approved by the Project Leader, not finished yet
 *   completed finished: verified expenses and payroll batches, fulfilled or
 *             cancelled purchase requests (each keeps its own status pill)
 * The Overview KPIs and the sidebar badge use these same queries.
 */
class ResearchAssistantService
{
    public const TYPES = ['expense', 'payroll', 'purchase_request'];
    public const TABS = ['drafts', 'waiting', 'returned', 'approved', 'completed'];

    private const TYPE_LABELS = [
        'expense' => 'Expense',
        'payroll' => 'Payroll',
        'purchase_request' => 'Purchase request',
    ];

    private const STAGES = [
        'drafts' => ['expense' => ['draft'], 'payroll' => ['draft'], 'purchase_request' => ['draft']],
        'waiting' => ['expense' => ['submitted'], 'payroll' => ['submitted'], 'purchase_request' => ['submitted']],
        'returned' => ['expense' => ['returned'], 'payroll' => ['returned'], 'purchase_request' => ['returned']],
        'approved' => ['expense' => ['endorsed'], 'payroll' => ['endorsed'], 'purchase_request' => ['endorsed']],
        'completed' => ['expense' => ['verified'], 'payroll' => ['verified'], 'purchase_request' => ['fulfilled', 'cancelled']],
    ];

    private const AUDIT_MODEL = [
        'expense' => 'Expense',
        'payroll' => 'PayrollBatch',
        'purchase_request' => 'PurchaseRequest',
    ];

    public function __construct(
        private ProjectFinancialService $financials,
        private PayrollService $payroll
    ) {
    }

    /** 403 unless the user is a Research Assistant of this project. */
    public function requireAssistant(Project $project, User $user): void
    {
        abort_unless($user->hasRoleOn($project, 'research-assistant'), 403, 'Only a Research Assistant of this project can open this page.');
    }

    // ---------------------------------------------------------------- counts

    /** Size of one tab (all types, or one). */
    public function count(Project $project, User $user, string $tab, ?string $type = null): int
    {
        $types = $type ? [$type] : self::TYPES;

        return array_sum(array_map(fn ($t) => $this->query($project, $user, $t, $tab)->count(), $types));
    }

    /** Sidebar badge: "My submissions" = Returned to you (same query as the KPI). */
    public function badges(Project $project, User $user): array
    {
        return ['my_submissions' => $this->count($project, $user, 'returned')];
    }

    // ---------------------------------------------------------------- lists

    /** @return array{counts: array<string, int>, items: array<int, array<string, mixed>>} */
    public function submissions(Project $project, User $user, string $tab = 'waiting', ?string $type = null): array
    {
        $types = $type ? [$type] : self::TYPES;
        $counts = [];
        foreach (self::TABS as $name) {
            $counts[$name] = $this->count($project, $user, $name, $type);
        }

        $items = collect($types)
            ->flatMap(fn ($t) => $this->query($project, $user, $t, $tab)->get()->map(fn ($record) => $this->item($t, $record, $user)))
            ->sortByDesc(fn ($item) => [$item['updated_at'] ?? '', $item['key']])
            ->values()
            ->all();

        return ['counts' => $counts, 'items' => $items];
    }

    /** One record of the project, whatever its stage (side panel). */
    public function show(Project $project, User $user, string $type, int $id): array
    {
        $record = $this->model($type)::query()->where('project_id', $project->id)->findOrFail($id);

        return $this->item($type, $record, $user, true);
    }

    public function overview(Project $project, User $user): array
    {
        $project->loadMissing('fundingAgency');
        $compliance = $this->financials->compliance($project);
        $leaders = $project->usersWithRole('project-leader')->orderBy('name')->pluck('name');

        $latest = collect(self::TYPES)
            ->flatMap(fn ($t) => $this->mine($project, $user, $t)->orderByDesc('updated_at')->orderByDesc('id')->limit(8)->get()
                ->map(fn ($record) => $this->item($t, $record, $user)))
            ->sortByDesc(fn ($item) => [$item['updated_at'] ?? '', $item['key']])
            ->take(8)
            ->values()
            ->all();

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'title' => $project->title,
                'funder' => $project->fundingAgency ? ($project->fundingAgency->acronym ?: $project->fundingAgency->name) : null,
                'project_leader' => $leaders->join(', ') ?: null,
            ],
            'kpis' => [
                'drafts' => $this->count($project, $user, 'drafts'),
                'waiting' => $this->count($project, $user, 'waiting'),
                'returned' => $this->count($project, $user, 'returned'),
                'missing_receipts' => $compliance['missing_receipt'],
            ],
            'submissions' => $latest,
            'missing_receipts' => $compliance['missing_items'],
        ];
    }

    /**
     * Documents of the project's expenses, payroll batches and purchase
     * requests (newest first), with the record each belongs to.
     *
     * @param  array{document_type?: string|null, record?: string|null}  $filters
     */
    public function documents(Project $project, array $filters): array
    {
        $classes = [
            'expense' => Expense::class,
            'payroll' => PayrollBatch::class,
            'purchase_request' => PurchaseRequest::class,
        ];
        $wanted = ! empty($filters['record']) ? [$filters['record'] => $classes[$filters['record']]] : $classes;

        $documents = Document::query()
            ->with(['uploader', 'documentable'])
            ->where(function (Builder $q) use ($wanted, $project) {
                foreach ($wanted as $class) {
                    $ids = $class::query()->where('project_id', $project->id)->pluck('id');
                    $q->orWhere(fn (Builder $one) => $one->where('documentable_type', (new $class())->getMorphClass())->whereIn('documentable_id', $ids));
                }
            })
            ->when($filters['document_type'] ?? null, fn ($q, $type) => $q->where('document_type', $type))
            ->orderByDesc('uploaded_at')->orderByDesc('id')
            ->limit(200)
            ->get();

        $typeOf = array_flip(array_map(fn ($class) => (new $class())->getMorphClass(), $classes));

        return [
            'documents' => $documents->map(function (Document $document) use ($typeOf) {
                $type = $typeOf[$document->documentable_type] ?? null;
                $record = $document->documentable;

                return \App\Http\Controllers\DocumentController::row($document) + [
                    'record' => $type && $record ? [
                        'type' => $type,
                        'id' => $record->id,
                        'type_label' => self::TYPE_LABELS[$type],
                        'reference' => $this->reference($type, $record),
                        'title' => $this->title($type, $record),
                    ] : null,
                ];
            })->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- queries

    private function model(string $type): string
    {
        return [
            'expense' => Expense::class,
            'payroll' => PayrollBatch::class,
            'purchase_request' => PurchaseRequest::class,
        ][$type];
    }

    private function mine(Project $project, User $user, string $type): Builder
    {
        $query = $this->model($type)::query()->where('project_id', $project->id);

        return match ($type) {
            'expense' => $query->where('recorded_by', $user->id),
            'purchase_request' => $query->where('requested_by', $user->id),
            'payroll' => $query->where(fn ($q) => $q->where('prepared_by', $user->id)->orWhere('submitted_by', $user->id)),
        };
    }

    private function query(Project $project, User $user, string $type, string $tab): Builder
    {
        $query = $this->mine($project, $user, $type);

        // 'all': every stage (the Expenses and Purchase requests pages).
        return $tab === 'all' ? $query : $query->whereIn('workflow_status', self::STAGES[$tab][$type]);
    }

    // ---------------------------------------------------------------- rows

    private function item(string $type, $record, User $user, bool $full = false): array
    {
        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;
        $status = $record->workflow_status;
        $returnedBy = $status === 'returned' ? $this->returnedBy($type, $record) : null;
        $documents = $record->documents()->get(['id', 'document_type', 'uploaded_by']);

        $base = [
            'key' => $type . '-' . $record->id,
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type],
            'id' => $record->id,
            'project_id' => $record->project_id,
            'reference' => $this->reference($type, $record),
            'title' => $this->title($type, $record),
            'workflow_status' => $status,
            'tab' => collect(self::STAGES)->search(fn ($stages) => in_array($status, $stages[$type], true)) ?: null,
            'return_reason' => $status === 'returned' ? $record->return_reason : null,
            'returned_by' => $person($returnedBy),
            'submitted_at' => $record->submitted_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
            'documents_count' => $documents->count(),
        ];

        $item = $base + match ($type) {
            'expense' => $this->expenseItem($record, $user, $documents),
            'payroll' => $this->payrollItem($record, $user, $documents),
            'purchase_request' => $this->purchaseRequestItem($record, $user, $documents),
        };

        if (! $full) {
            unset($item['fields'], $item['edit']);
        }

        return $item;
    }

    private function expenseItem(Expense $expense, User $user, Collection $documents): array
    {
        $expense->loadMissing(['libCategory', 'recordedBy', 'verifiedBy', 'plReviewedBy', 'purchaseRequest']);
        $mine = (int) $expense->recorded_by === (int) $user->id;
        $receipts = $documents->where('document_type', Document::TYPE_RECEIPT)->count();
        $editable = in_array($expense->workflow_status, ['draft', 'returned'], true);

        return [
            'details' => trim(implode(' · ', array_filter([$expense->libCategory?->line_item_name, $expense->payee]))),
            'line_item' => $expense->libCategory?->line_item_name,
            'amount' => round((float) $expense->amount, 2),
            'workflow_label' => ExpenseWorkflowService::label($expense),
            'next_step' => match ($expense->workflow_status) {
                'draft' => $receipts > 0 ? 'Submit when ready' : 'Attach the receipt, then submit',
                'submitted' => 'Waiting for the Project Leader',
                'endorsed' => 'Waiting for Finance/Admin verification',
                'returned' => 'Correct it and submit again',
                'verified' => $receipts > 0 ? 'Counted in the budget' : 'Counted in the budget · receipt missing',
                default => null,
            },
            'receipts_count' => $receipts,
            'submitted_by' => ['id' => $expense->recordedBy?->id, 'name' => $expense->recordedBy?->name],
            'fields' => [
                ['label' => 'Line item', 'value' => $expense->libCategory?->line_item_name],
                ['label' => 'Expense date', 'value' => $expense->expense_date?->toDateString(), 'kind' => 'date'],
                ['label' => 'Payee', 'value' => $expense->payee],
                ['label' => 'Reference no.', 'value' => $expense->reference_no],
                ['label' => 'Receipts', 'value' => (string) $receipts],
                ['label' => 'Purchase request', 'value' => $expense->purchaseRequest ? 'PR-' . $expense->purchaseRequest->id . ' · ' . $expense->purchaseRequest->description : null],
                ['label' => 'Project Leader', 'value' => $expense->plReviewedBy?->name],
                ['label' => 'Verified by', 'value' => $expense->workflow_status === 'verified' ? $expense->verifiedBy?->name : null],
            ],
            'can_edit' => $mine && $editable,
            'can_delete' => $mine && $editable,
            'can_submit' => $mine && $editable && $receipts > 0,
            'can_cancel' => false,
            'can_attach_receipt' => $expense->awaitsLateReceipt(),
            'edit' => $mine ? [
                'lib_category_id' => $expense->lib_category_id,
                'funding_source' => $expense->funding_source ?: 'funder',
                'description' => $expense->description,
                'amount' => round((float) $expense->amount, 2),
                'expense_date' => $expense->expense_date?->toDateString(),
                'payee' => $expense->payee,
                'reference_no' => $expense->reference_no,
                'purchase_request_id' => $expense->purchase_request_id,
            ] : null,
        ];
    }

    private function payrollItem(PayrollBatch $batch, User $user, Collection $documents): array
    {
        $batch->loadMissing(['rows', 'verifiedBy', 'plReviewedBy']);
        $rows = $batch->rows;
        $people = $rows->count() . ' ' . ($rows->count() === 1 ? 'person' : 'people');
        $mine = in_array((int) $user->id, [(int) $batch->prepared_by, (int) $batch->submitted_by], true);

        return [
            'details' => $people . ' · net ₱' . number_format((float) $rows->sum('net_pay'), 2),
            'line_item' => null,
            'month' => $batch->monthKey(),
            'amount' => round((float) $rows->sum('gross_pay'), 2),
            'workflow_label' => PayrollService::LABELS[$batch->workflow_status] ?? $batch->workflow_status,
            'next_step' => match ($batch->workflow_status) {
                'draft' => $documents->where('document_type', 'payroll_file')->isEmpty() ? 'Upload the payroll file, then submit' : 'Submit when ready',
                'returned' => 'Correct it and submit again',
                'verified' => 'Verified (counted in spent)',
                default => $this->payroll->waitingText($batch, $user),
            },
            'escalated' => $batch->pendingEscalation() !== null,
            'submitted_by' => ['id' => $batch->submitted_by ?? $batch->prepared_by, 'name' => optional(User::find($batch->submitted_by ?? $batch->prepared_by))->name],
            'fields' => [
                ['label' => 'Pay period', 'value' => $batch->monthLabel()],
                ['label' => 'People', 'value' => (string) $rows->count()],
                ['label' => 'Gross pay', 'value' => round((float) $rows->sum('gross_pay'), 2), 'kind' => 'money'],
                ['label' => 'Net pay', 'value' => round((float) $rows->sum('net_pay'), 2), 'kind' => 'money'],
                ['label' => 'Project Leader', 'value' => $batch->plReviewedBy?->name],
                ['label' => 'Verified by', 'value' => $batch->workflow_status === 'verified' ? $batch->verifiedBy?->name : null],
            ],
            'can_edit' => $mine && $batch->isEditable(),
            'can_delete' => false,
            'can_submit' => $mine && $batch->isEditable() && $rows->isNotEmpty(),
            'can_cancel' => false,
            'can_attach_receipt' => false,
            'edit' => null,
        ];
    }

    private function purchaseRequestItem(PurchaseRequest $request, User $user, Collection $documents): array
    {
        $request->loadMissing(['libCategory', 'requestedBy', 'plReviewedBy', 'expense']);
        $mine = (int) $request->requested_by === (int) $user->id;
        $editable = in_array($request->workflow_status, ['draft', 'returned'], true);
        $supporting = $documents->whereIn('document_type', ['quotation', 'invoice'])->count();

        return [
            'details' => trim(implode(' · ', array_filter([$request->libCategory?->line_item_name, 'Qty ' . $request->quantity]))),
            'line_item' => $request->libCategory?->line_item_name,
            'amount' => round((float) $request->estimated_amount, 2),
            'workflow_label' => PurchaseRequestService::label($request),
            'next_step' => match ($request->workflow_status) {
                'draft' => $supporting > 0 ? 'Submit when ready' : 'Attach a quotation or invoice, then submit',
                'submitted' => 'Waiting for the Project Leader',
                'endorsed' => 'Approved. Record the expense and link it to this request',
                'returned' => 'Correct it and submit again',
                'fulfilled' => 'Fulfilled by a recorded expense',
                'cancelled' => 'Cancelled' . ($request->cancel_reason ? ': “' . $request->cancel_reason . '”' : ''),
                default => null,
            },
            'submitted_by' => ['id' => $request->requestedBy?->id, 'name' => $request->requestedBy?->name],
            'fields' => [
                ['label' => 'Line item', 'value' => $request->libCategory?->line_item_name],
                ['label' => 'Quantity', 'value' => (string) $request->quantity],
                ['label' => 'Estimated amount', 'value' => round((float) $request->estimated_amount, 2), 'kind' => 'money'],
                ['label' => 'Supplier / payee', 'value' => $request->supplier],
                ['label' => 'Project Leader', 'value' => $request->plReviewedBy?->name],
                ['label' => 'Linked expense', 'value' => $request->expense ? ($request->expense->reference_no ?: 'EXP-' . $request->expense->id) . ' · ' . $request->expense->description : null],
                ['label' => 'Cancel reason', 'value' => $request->cancel_reason],
            ],
            'can_edit' => $mine && $editable,
            'can_delete' => $mine && $request->workflow_status === 'draft',
            'can_submit' => $mine && $editable && $supporting > 0,
            'can_cancel' => $mine && $request->workflow_status === PurchaseRequest::OPEN_APPROVED,
            'can_attach_receipt' => false,
            'edit' => $mine ? [
                'lib_category_id' => $request->lib_category_id,
                'funding_source' => $request->funding_source ?: 'funder',
                'description' => $request->description,
                'quantity' => $request->quantity,
                'estimated_amount' => round((float) $request->estimated_amount, 2),
                'supplier' => $request->supplier,
            ] : null,
        ];
    }

    private function reference(string $type, $record): string
    {
        return match ($type) {
            'expense' => $record->reference_no ?: 'EXP-' . $record->id,
            'payroll' => $record->reference(),
            'purchase_request' => 'PR-' . $record->id,
        };
    }

    private function title(string $type, $record): string
    {
        return match ($type) {
            'payroll' => 'Payroll ' . $record->monthLabel(),
            default => (string) $record->description,
        };
    }

    /**
     * Who sent a returned record back: the latest "returned" / "rejected"
     * audit entry on it, else the reviewer columns (latest decision).
     */
    private function returnedBy(string $type, $record): ?User
    {
        $entry = AuditTrail::query()
            ->where('model_type', self::AUDIT_MODEL[$type])->where('model_id', $record->id)
            ->whereIn('action', ['returned', 'rejected'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first();
        if ($entry && $entry->user_id) {
            return User::find($entry->user_id);
        }

        $leaderAt = $record->pl_reviewed_at ?? null;
        $financeAt = $record->verified_at ?? null;
        $financeId = $record->verified_by ?? null;
        if ($financeId && (! $leaderAt || ($financeAt && $financeAt->greaterThan($leaderAt)))) {
            return User::find($financeId);
        }

        return $record->pl_reviewed_by ? User::find($record->pl_reviewed_by) : null;
    }
}

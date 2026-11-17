<?php

namespace App\Services;

use App\Http\Controllers\DocumentController;
use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Everything the Program Leader needs to decide one approval request, for
 * the detail side panel: who asked and when, the status history, the
 * type-specific figures and the attachments. Figures come from
 * ProjectFinancialService so they match every other screen.
 */
class ApprovalDetailService
{
    private const HISTORY = [
        'requested' => 'Submitted',
        'approved' => 'Approved',
        'returned' => 'Returned',
        'rejected' => 'Returned',
        'resubmitted' => 'Resubmitted',
        'withdrawn' => 'Withdrawn',
    ];

    public function __construct(private ProjectFinancialService $financials)
    {
    }

    public function detail(ApprovalRequest $approval, User $viewer): array
    {
        $approval->loadMissing(['project', 'requestedBy.primaryRole', 'reviewedBy', 'withdrawnBy', 'tranche', 'liquidation']);
        $project = $approval->project;
        $batch = PayrollService::isEscalation($approval)
            ? PayrollBatch::query()->find(($approval->payload ?? [])['payroll_batch_id'] ?? 0)
            : null;

        [$canDecide, $blocked] = $this->decidable($approval, $viewer);

        return [
            'id' => $approval->id,
            'type' => $approval->type,
            'type_label' => $approval->typeLabel(),
            'title' => $approval->type === 'liquidation' ? ($approval->liquidation?->title ?? $approval->title) : $approval->title,
            'description' => $approval->description,
            'justification' => $approval->justification,
            'amount' => $approval->amount !== null ? (float) $approval->amount : null,
            'project' => $project ? ['id' => $project->id, 'code' => $project->code, 'title' => $project->title] : null,
            'requested_by' => $approval->requestedBy ? [
                'id' => $approval->requestedBy->id,
                'name' => $approval->requestedBy->name,
                'role' => $approval->requestedBy->primaryRole?->name,
            ] : null,
            'created_at' => $approval->created_at?->toIso8601String(),
            'resubmitted_at' => $approval->resubmitted_at?->toIso8601String(),
            'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
            'reviewed_by' => $approval->reviewedBy?->name ?? $approval->reviewed_by_name,
            'withdrawn_at' => $approval->withdrawn_at?->toIso8601String(),
            'status' => $approval->displayStatus(),
            'status_label' => $approval->statusLabel(),
            'review_notes' => $approval->review_notes,
            'last_return_reason' => $approval->last_return_reason,
            'history' => $this->history($approval),
            'details' => $project ? $this->typeDetails($approval, $project, $batch) : null,
            'documents' => $this->documents($approval, $batch),
            // Approve / Return only inside the panel, for the Program Leader, on a pending request someone else made.
            'can_decide' => $canDecide,
            'blocked_text' => $blocked,
        ];
    }

    /** @return array{0: bool, 1: ?string} */
    private function decidable(ApprovalRequest $approval, User $viewer): array
    {
        if (! $approval->isPending()) {
            return [false, null];
        }
        if (! $viewer->isProgramLeader()) {
            return [false, 'Waiting for the Program Leader.'];
        }
        if ((int) $approval->requested_by === (int) $viewer->id) {
            return [false, 'You cannot approve a request you created.'];
        }

        return [true, null];
    }

    /** Submitted, returned (with reason), resubmitted, ... oldest first. */
    private function history(ApprovalRequest $approval): array
    {
        $entries = AuditTrail::query()
            ->with('user')
            ->where('model_type', 'ApprovalRequest')
            ->where('model_id', $approval->id)
            ->whereIn('action', array_keys(self::HISTORY))
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn (AuditTrail $entry) => [
                'event' => $entry->action === 'rejected' ? 'returned' : $entry->action,
                'label' => self::HISTORY[$entry->action],
                'at' => $entry->created_at ? Carbon::parse($entry->created_at)->toIso8601String() : null,
                'by' => $entry->user?->name,
                'reason' => $entry->reason,
            ])
            ->values();

        // Rows made before these entries existed (seeded, payroll escalations): fall back to the row itself.
        if (! $entries->contains('event', 'requested')) {
            $entries->prepend([
                'event' => 'requested', 'label' => 'Submitted',
                'at' => $approval->created_at?->toIso8601String(),
                'by' => $approval->requestedBy?->name, 'reason' => null,
            ]);
        }
        $decided = $approval->status === 'approved' ? 'approved' : ($approval->status === ApprovalRequest::STORED_RETURNED ? 'returned' : null);
        if ($decided && $approval->reviewed_at && ! $entries->contains('event', $decided)) {
            $entries->push([
                'event' => $decided, 'label' => self::HISTORY[$decided],
                'at' => $approval->reviewed_at->toIso8601String(),
                'by' => $approval->reviewedBy?->name ?? $approval->reviewed_by_name,
                'reason' => $decided === 'returned' ? $approval->review_notes : null,
            ]);
        }
        if ($approval->isWithdrawn() && ! $entries->contains('event', 'withdrawn')) {
            $entries->push([
                'event' => 'withdrawn', 'label' => 'Withdrawn',
                'at' => $approval->withdrawn_at->toIso8601String(),
                'by' => $approval->withdrawnBy?->name, 'reason' => null,
            ]);
        }

        return $entries->values()->all();
    }

    private function typeDetails(ApprovalRequest $approval, Project $project, ?PayrollBatch $batch): ?array
    {
        return match ($approval->type) {
            'disbursement' => $this->fundRelease($approval, $project),
            'budget_realignment' => $this->realignment($approval, $project),
            'liquidation' => $this->liquidation($approval, $project),
            'payroll' => $batch ? $this->payroll($approval, $batch) : null,
            default => null,
        };
    }

    private function fundRelease(ApprovalRequest $approval, Project $project): array
    {
        $tranches = ProjectTranche::query()->where('project_id', $project->id)->orderBy('sequence')->orderBy('id')->get();
        $released = $tranches->where('status', 'released');
        $metrics = $this->financials->financials($project);

        return [
            'kind' => 'fund_release',
            'tranche' => $approval->tranche ? $this->trancheRow($approval->tranche) : null,
            'released' => [
                'total' => round((float) $released->sum('amount'), 2),
                'tranches' => $released->map(fn (ProjectTranche $t) => $this->trancheRow($t))->values()->all(),
            ],
            'budget' => [
                'budget' => $metrics['budget'],
                'spent' => $metrics['spent'],
                'remaining' => $metrics['remaining'],
            ],
        ];
    }

    /**
     * Source and destination line items, before and after the move. Pending
     * (or returned / withdrawn): "before" is now and "after" applies the
     * amount. Approved: the move is already in the allocations, so "after"
     * is now and "before" takes it back out.
     */
    private function realignment(ApprovalRequest $approval, Project $project): array
    {
        $payload = $approval->payload ?? [];
        $amount = round((float) ($payload['amount'] ?? $approval->amount ?? 0), 2);
        $applied = $approval->status === 'approved';
        $rows = collect($this->financials->balancing($project)['line_items'])->keyBy('id');

        $side = function (?int $id, int $sign) use ($rows, $amount, $applied) {
            $row = $id ? $rows->get($id) : null;
            if (! $row) {
                return null;
            }
            $now = ['allocated' => $row['allocated'], 'spent' => $row['spent'], 'pending' => $row['pending'], 'available' => $row['available']];
            $moved = $now;
            $delta = $sign * $amount * ($applied ? -1 : 1);
            $moved['allocated'] = round($now['allocated'] + $delta, 2);
            $moved['available'] = round($now['available'] + $delta, 2);

            return [
                'id' => $row['id'], 'code' => $row['code'], 'name' => $row['name'], 'type' => $row['type'],
                'before' => $applied ? $moved : $now,
                'after' => $applied ? $now : $moved,
            ];
        };

        return [
            'kind' => 'realignment',
            'amount' => $amount,
            'applied' => $applied,
            'from' => $side((int) ($payload['from_lib_category_id'] ?? 0) ?: null, -1),
            'to' => $side((int) ($payload['to_lib_category_id'] ?? 0) ?: null, 1),
        ];
    }

    /**
     * The tranche being liquidated and the verified expenses charged against
     * it. Expenses are not linked to a tranche, so a tranche's expenses are
     * the verified ones dated from its release up to the day before the next
     * released tranche's release (the last released tranche runs to today).
     * The first released tranche starts at the project start date instead,
     * so expenses dated before the first release are counted too.
     */
    private function liquidation(ApprovalRequest $approval, Project $project): array
    {
        $trancheId = $approval->project_tranche_id ?? $approval->liquidation?->project_tranche_id;
        $tranche = $trancheId ? ProjectTranche::query()->where('project_id', $project->id)->find($trancheId) : null;

        $expenses = collect();
        $window = null;
        if ($tranche && $tranche->status === 'released' && $tranche->released_at) {
            $next = ProjectTranche::query()
                ->where('project_id', $project->id)
                ->where('status', 'released')
                ->whereNotNull('released_at')
                ->whereKeyNot($tranche->id)
                ->whereDate('released_at', '>', $tranche->released_at->toDateString())
                ->orderBy('released_at')
                ->first();
            $isFirst = ! ProjectTranche::query()
                ->where('project_id', $project->id)
                ->where('status', 'released')
                ->whereNotNull('released_at')
                ->whereKeyNot($tranche->id)
                ->whereDate('released_at', '<', $tranche->released_at->toDateString())
                ->exists();
            $start = $isFirst && $project->start_date ? Carbon::parse($project->start_date) : null;
            $fromStart = $start !== null && $start->lt($tranche->released_at);
            $from = $fromStart ? $start : $tranche->released_at;
            $window = [
                'from' => $from->toDateString(),
                'to' => $next?->released_at?->copy()->subDay()->toDateString(),
                // project_start (first released tranche) | release
                'starts' => $fromStart ? 'project_start' : 'release',
            ];

            $expenses = ProjectFinancialService::onlySpent(Expense::query()->where('project_id', $project->id))
                ->with('libCategory')
                ->whereDate('expense_date', '>=', $window['from'])
                ->when($window['to'], fn ($q, $to) => $q->whereDate('expense_date', '<=', $to))
                ->orderBy('expense_date')->orderBy('id')
                ->get();
        }

        return [
            'kind' => 'liquidation',
            'tranche' => $tranche ? $this->trancheRow($tranche) : null,
            'released_amount' => $tranche && $tranche->status === 'released' ? round((float) $tranche->amount, 2) : 0.0,
            'period' => $window,
            'expenses' => $expenses->map(fn (Expense $e) => [
                'id' => $e->id,
                'date' => $e->expense_date ? Carbon::parse($e->expense_date)->toDateString() : null,
                'reference_no' => $e->reference_no,
                'payee' => $e->payee,
                'description' => $e->description,
                'line_item' => $e->libCategory?->line_item_name,
                'amount' => round((float) $e->amount, 2),
            ])->values()->all(),
            'expenses_total' => round((float) $expenses->sum('amount'), 2),
        ];
    }

    private function payroll(ApprovalRequest $approval, PayrollBatch $batch): array
    {
        $rows = $batch->rows()->get();

        return [
            'kind' => 'payroll',
            'batch_id' => $batch->id,
            'month' => $batch->monthLabel(),
            'step' => (($approval->payload ?? [])['step'] ?? 'approve') === 'verify' ? 'Verify' : 'Approve',
            'workflow_status' => $batch->workflow_status,
            'people' => $rows->count(),
            'gross_total' => round((float) $rows->sum('gross_pay'), 2),
            'deductions_total' => round((float) $rows->sum('total_deductions'), 2),
            'net_total' => round((float) $rows->sum('net_pay'), 2),
        ];
    }

    /** The request's own files, plus the batch files for an escalated payroll step. */
    private function documents(ApprovalRequest $approval, ?PayrollBatch $batch): array
    {
        $documents = $approval->documents()->with('uploader')->orderBy('id')->get();
        if ($batch) {
            $documents = $documents->concat($batch->documents()->with('uploader')->orderBy('id')->get());
        }

        return $documents->map(fn (Document $d) => DocumentController::row($d))->values()->all();
    }

    private function trancheRow(ProjectTranche $tranche): array
    {
        return [
            'id' => $tranche->id,
            'name' => $tranche->name,
            'sequence' => $tranche->sequence,
            'amount' => round((float) $tranche->amount, 2),
            'planned_release_date' => $tranche->planned_release_date?->toDateString(),
            'status' => $tranche->status,
            'released_at' => $tranche->released_at?->toDateString(),
        ];
    }
}

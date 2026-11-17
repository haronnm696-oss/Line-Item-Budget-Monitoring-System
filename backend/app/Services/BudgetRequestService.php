<?php

namespace App\Services;

use App\Http\Controllers\DocumentController;
use App\Models\ApprovalRequest;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\Concerns\RunsWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Budget requests from a project's Project Leader to the Program Leader:
 * fund release (a tranche), realignment (from one line item to another) and
 * liquidation (with documents). Each becomes a pending ApprovalRequest on
 * the Program Leader Approvals page. The Program Leader is notified on
 * create; the requester on the decision (see notifyDecision()).
 *
 * The Program Leader approves or returns (with a reason). A returned
 * request goes back to its maker, who edits and resubmits it (pending
 * again, same row) or withdraws it. A returned or withdrawn fund release no
 * longer holds its tranche (only pending requests do).
 */
class BudgetRequestService
{
    use RunsWorkflow;

    /** API type -> stored approval_requests.type */
    public const TYPES = [
        'fund_release' => 'disbursement',
        'realignment' => 'budget_realignment',
        'liquidation' => 'liquidation',
    ];

    public function __construct(private ProjectFinancialService $financials)
    {
    }

    /** @param UploadedFile[] $files */
    public function create(Project $project, array $data, array $files, User $actor): ApprovalRequest
    {
        $this->requireRole($project, $actor, ['project-leader'], 'Only the Project Leader of this project can create budget requests.');

        $type = self::TYPES[$data['type']];
        $attributes = match ($type) {
            'disbursement' => $this->fundRelease($project, $data),
            'budget_realignment' => $this->realignment($project, $data),
            'liquidation' => $this->liquidation($project, $data, $files),
        };

        $approval = AuditLogger::transaction(function () use ($project, $data, $files, $actor, $type, $attributes) {
            $approval = ApprovalRequest::create(array_merge([
                'project_id' => $project->id,
                'type' => $type,
                'description' => $data['description'] ?? null,
                'justification' => trim((string) ($data['justification'] ?? '')),
                'requested_by' => $actor->id,
                'status' => 'pending',
            ], $attributes));

            AuditLogger::write('requested', $approval, [], $approval->only([
                'project_id', 'type', 'title', 'amount', 'payload', 'project_tranche_id', 'justification', 'status',
            ]), $actor);

            foreach ($files as $file) {
                DocumentController::attach($approval, $file, $data['document_type'] ?? 'receipt', $actor);
            }

            return $approval;
        });

        $this->notifyProgramLeaders($approval->fresh(['project']), $actor);

        return $approval->fresh(['requestedBy', 'documents']);
    }

    /** Tell the requester the Program Leader's decision (after it is committed). */
    public function notifyDecision(ApprovalRequest $approval, User $actor): void
    {
        // Only budget requests (payroll escalations notify through PayrollService).
        if (! in_array($approval->type, self::TYPES, true)) {
            return;
        }
        $approval->loadMissing('project');
        $approved = $approval->status === 'approved';
        $because = $approval->review_notes ? ($approved ? ' Remarks: ' : ' Reason: ') . $approval->review_notes : '';

        $this->notifyUsers($this->activeUser($approval->requested_by), new WorkflowNotification([
            'kind' => 'budget_request',
            'event' => $approved ? 'approved' : 'returned',
            'title' => $approved ? 'Budget request approved' : 'Budget request returned',
            'message' => $approved
                ? "{$actor->name} approved your {$approval->typeLabel()} request: {$approval->title}.{$because}"
                : "{$actor->name} returned your {$approval->typeLabel()} request for changes: {$approval->title}.{$because} Edit and resubmit it, or withdraw it, from My requests.",
            'approval_id' => $approval->id,
            'project_id' => $approval->project_id,
            'project' => $approval->project?->title,
            'actor' => $actor->name,
            'reason' => $approval->review_notes,
            'link' => '/my-requests?project=' . $approval->project_id . '&request=' . $approval->id,
        ]), $actor);
    }

    /**
     * Edit a returned request and send it back to the Program Leader (same
     * row, pending again). The type stays; the other fields are checked as
     * on create. $removeDocumentIds are the maker's documents to delete;
     * $files are added. One audit entry ("resubmitted") on the request, one
     * notification per Program Leader.
     *
     * @param UploadedFile[] $files
     * @param int[] $removeDocumentIds
     */
    public function resubmit(ApprovalRequest $approval, array $data, array $files, array $removeDocumentIds, User $actor): ApprovalRequest
    {
        $this->requireBudgetRequest($approval);
        $this->requireMaker($approval, $actor, 'Only the person who made this request can edit and resubmit it.');
        $this->requireRole($approval->project, $actor, ['project-leader'], 'Only the Project Leader of this project can resubmit budget requests.');
        $this->requireReturned($approval, 'resubmitted');

        $project = $approval->project;
        $removeDocumentIds = array_values(array_unique(array_map('intval', $removeDocumentIds)));
        $remove = $approval->documents()->whereIn('id', $removeDocumentIds)->get();
        if ($remove->count() !== count($removeDocumentIds)) {
            throw ValidationException::withMessages(['remove_document_ids' => ['Choose documents of this request.']]);
        }
        if ($remove->contains(fn ($doc) => (int) $doc->uploaded_by !== (int) $actor->id)) {
            throw ValidationException::withMessages(['remove_document_ids' => ['You can only remove documents you uploaded.']]);
        }
        $kept = $approval->documents()->count() - $remove->count();

        $attributes = match ($approval->type) {
            'disbursement' => $this->fundRelease($project, $data),
            'budget_realignment' => $this->realignment($project, $data),
            'liquidation' => $this->liquidation($project, $data, $files, $kept),
        };

        $removed = AuditLogger::transaction(function () use ($approval, $data, $files, $remove, $actor, $attributes) {
            $locked = $this->lockRecord($approval);
            $this->requireReturned($locked, 'resubmitted');
            $fields = ['title', 'amount', 'payload', 'project_tranche_id', 'description', 'justification', 'status', 'review_notes', 'last_return_reason'];
            $old = $locked->only($fields);

            $locked->update(array_merge([
                'payload' => null,
                'project_tranche_id' => null,
            ], $attributes, [
                'description' => array_key_exists('description', $data) ? $data['description'] : $locked->description,
                'justification' => trim((string) ($data['justification'] ?? '')),
                'status' => 'pending',
                'last_return_reason' => $locked->review_notes,
                'review_notes' => null,
                'reviewed_by' => null,
                'reviewed_by_name' => null,
                'reviewed_by_role' => null,
                'reviewed_at' => null,
                'resubmitted_at' => now(),
            ]));
            $locked->liquidation?->update(['status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'remarks' => null]);

            foreach ($remove as $document) {
                DocumentController::detach($document, $locked, $actor);
            }
            foreach ($files as $file) {
                DocumentController::attach($locked, $file, $data['document_type'] ?? ($locked->type === 'liquidation' ? 'receipt' : 'other'), $actor);
            }

            AuditLogger::write('resubmitted', $locked, $old, $locked->fresh()->only($fields), $actor);

            return $remove;
        });
        DocumentController::deleteFiles($removed);

        $this->notifyProgramLeaders($approval->fresh(['project']), $actor, true);

        return $approval->fresh(['requestedBy', 'documents']);
    }

    /** The maker closes a returned request without resubmitting it. One audit entry. */
    public function withdraw(ApprovalRequest $approval, ?string $reason, User $actor): ApprovalRequest
    {
        $this->requireBudgetRequest($approval);
        $this->requireMaker($approval, $actor, 'Only the person who made this request can withdraw it.');
        $this->requireReturned($approval, 'withdrawn');

        return AuditLogger::transaction(function () use ($approval, $reason, $actor) {
            $locked = $this->lockRecord($approval);
            $this->requireReturned($locked, 'withdrawn');
            $locked->update(['withdrawn_at' => now(), 'withdrawn_by' => $actor->id]);
            AuditLogger::write('withdrawn', $locked, ['withdrawn_at' => null, 'withdrawn_by' => null], $locked->fresh()->only(['withdrawn_at', 'withdrawn_by']), $actor, trim((string) $reason) ?: null);

            return $locked->fresh(['requestedBy', 'documents']);
        });
    }

    // ---------------------------------------------------------------- types

    private function fundRelease(Project $project, array $data): array
    {
        $tranche = ProjectTranche::query()->where('project_id', $project->id)->find($data['project_tranche_id'] ?? 0);
        if (! $tranche) {
            throw ValidationException::withMessages(['project_tranche_id' => ['Choose a tranche of this project.']]);
        }
        if ($tranche->status === 'released') {
            throw ValidationException::withMessages(['project_tranche_id' => ['This tranche has already been released.']]);
        }
        $pending = ApprovalRequest::query()
            ->where('project_tranche_id', $tranche->id)->where('type', 'disbursement')->where('status', 'pending')
            ->exists();
        if ($pending) {
            throw ValidationException::withMessages(['project_tranche_id' => ['A fund release request for this tranche is already pending.']]);
        }

        return [
            'project_tranche_id' => $tranche->id,
            'title' => 'Fund release: ' . $tranche->name,
            'amount' => $tranche->amount,
        ];
    }

    private function realignment(Project $project, array $data): array
    {
        $from = LibCategory::query()->where('project_id', $project->id)->find($data['from_lib_category_id'] ?? 0);
        $to = LibCategory::query()->where('project_id', $project->id)->find($data['to_lib_category_id'] ?? 0);
        if (! $from || ! $to) {
            throw ValidationException::withMessages(['from_lib_category_id' => ['Choose two line items of this project.']]);
        }
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_lib_category_id' => ['The source and destination line items must be different.']]);
        }

        $amount = round((float) $data['amount'], 2);
        $available = $this->financials->balancing($project, $from->id)['line_items'][0]['available'] ?? 0.0;
        if ($amount > $available) {
            throw ValidationException::withMessages([
                'amount' => ['The source line item has only ₱' . number_format($available, 2) . ' available.'],
            ]);
        }

        return [
            'title' => 'Realignment: ' . $from->line_item_name . ' → ' . $to->line_item_name,
            'amount' => $amount,
            'payload' => [
                'from_lib_category_id' => $from->id,
                'to_lib_category_id' => $to->id,
                'amount' => $amount,
            ],
        ];
    }

    private function liquidation(Project $project, array $data, array $files, int $keptDocuments = 0): array
    {
        if ($files === [] && $keptDocuments === 0) {
            throw ValidationException::withMessages(['files' => ['Attach at least one supporting document.']]);
        }

        $trancheId = $data['project_tranche_id'] ?? null;
        if ($trancheId && ! ProjectTranche::query()->where('project_id', $project->id)->whereKey($trancheId)->exists()) {
            throw ValidationException::withMessages(['project_tranche_id' => ['Choose a tranche of this project.']]);
        }

        return [
            'project_tranche_id' => $trancheId,
            'title' => trim((string) ($data['title'] ?? '')) ?: 'Liquidation',
            'amount' => round((float) $data['amount'], 2),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function requireBudgetRequest(ApprovalRequest $approval): void
    {
        abort_unless(in_array($approval->type, self::TYPES, true), 404, 'Budget request not found.');
    }

    private function requireMaker(ApprovalRequest $approval, User $actor, string $message): void
    {
        abort_unless((int) $approval->requested_by === (int) $actor->id, 403, $message);
    }

    private function requireReturned(ApprovalRequest $approval, string $verb): void
    {
        if (! $approval->isReturned()) {
            $label = strtolower($approval->statusLabel());
            throw ValidationException::withMessages([
                'status' => ["This budget request cannot be {$verb} while it is {$label}."],
            ]);
        }
    }

    private function notifyProgramLeaders(ApprovalRequest $approval, User $actor, bool $resubmitted = false): void
    {
        $leaders = User::query()
            ->where('is_active', true)
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))
            ->get();
        $amount = $approval->amount !== null ? ' (₱' . number_format((float) $approval->amount, 2) . ')' : '';

        $this->notifyUsers($leaders, new WorkflowNotification([
            'kind' => 'budget_request',
            'event' => $resubmitted ? 'resubmitted' : 'requested',
            'title' => $resubmitted ? 'Budget request resubmitted' : 'New budget request',
            'message' => $resubmitted
                ? "{$actor->name} edited and resubmitted a returned {$approval->typeLabel()} for {$approval->project?->title}: {$approval->title}{$amount}."
                : "{$actor->name} requested a {$approval->typeLabel()} for {$approval->project?->title}: {$approval->title}{$amount}.",
            'approval_id' => $approval->id,
            'project_id' => $approval->project_id,
            'project' => $approval->project?->title,
            'actor' => $actor->name,
            'reason' => null,
            'link' => '/program-approvals?project=' . $approval->project_id,
        ]), $actor);
    }

    protected function stageLabels(): array
    {
        return [];
    }

    protected function noun(): string
    {
        return 'budget request';
    }
}

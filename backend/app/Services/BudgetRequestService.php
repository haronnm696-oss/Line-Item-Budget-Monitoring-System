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
        $approval->loadMissing('project');
        $approved = $approval->status === 'approved';
        $because = $approval->review_notes ? ' Remarks: ' . $approval->review_notes : '';

        $this->notifyUsers($this->activeUser($approval->requested_by), new WorkflowNotification([
            'kind' => 'budget_request',
            'event' => $approved ? 'approved' : 'rejected',
            'title' => $approved ? 'Budget request approved' : 'Budget request rejected',
            'message' => "{$actor->name} " . ($approved ? 'approved' : 'rejected') . " your {$approval->typeLabel()} request: {$approval->title}.{$because}",
            'approval_id' => $approval->id,
            'project_id' => $approval->project_id,
            'project' => $approval->project?->title,
            'actor' => $actor->name,
            'reason' => $approval->review_notes,
            'link' => '/my-requests?project=' . $approval->project_id,
        ]), $actor);
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

    private function liquidation(Project $project, array $data, array $files): array
    {
        if ($files === []) {
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

    private function notifyProgramLeaders(ApprovalRequest $approval, User $actor): void
    {
        $leaders = User::query()
            ->where('is_active', true)
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))
            ->get();
        $amount = $approval->amount !== null ? ' (₱' . number_format((float) $approval->amount, 2) . ')' : '';

        $this->notifyUsers($leaders, new WorkflowNotification([
            'kind' => 'budget_request',
            'event' => 'requested',
            'title' => 'New budget request',
            'message' => "{$actor->name} requested a {$approval->typeLabel()} for {$approval->project?->title}: {$approval->title}{$amount}.",
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

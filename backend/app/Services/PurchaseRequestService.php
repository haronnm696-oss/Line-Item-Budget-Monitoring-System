<?php

namespace App\Services;

use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\Concerns\RunsWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Purchase request workflow: draft -> submitted -> endorsed ("Approved by
 * Project Leader") or returned (back to draft-like editing). No Finance
 * step. A submit needs a quotation or invoice document. One audit entry per
 * step; the Project Leader is notified on submit, the requester on a
 * decision.
 */
class PurchaseRequestService
{
    use RunsWorkflow;

    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'endorsed' => 'Approved by Project Leader',
        'returned' => 'Returned',
    ];

    private const ENCODERS = ['research-assistant', 'finance-admin'];
    private const EDITABLE = ['draft', 'returned'];
    private const FIELDS = ['lib_category_id', 'description', 'quantity', 'estimated_amount'];

    public function create(Project $project, array $data, User $actor): PurchaseRequest
    {
        $this->requireRole($project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can create purchase requests.');

        return AuditLogger::transaction(function () use ($project, $data, $actor) {
            $request = PurchaseRequest::create(array_merge(array_intersect_key($data, array_flip(self::FIELDS)), [
                'project_id' => $project->id,
                'requested_by' => $actor->id,
                'workflow_status' => 'draft',
            ]));
            AuditLogger::write('created', $request, [], $this->snapshot($request), $actor);

            return $request->fresh();
        });
    }

    public function update(PurchaseRequest $request, array $data, User $actor): PurchaseRequest
    {
        $this->requireRole($request->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can edit purchase requests.');
        $this->requireState($request, self::EDITABLE, 'edited');

        return AuditLogger::transaction(function () use ($request, $data, $actor) {
            $locked = $this->lockRecord($request);
            $this->requireState($locked, self::EDITABLE, 'edited');
            $old = $this->snapshot($locked);
            $locked->update(array_intersect_key($data, array_flip(self::FIELDS)));
            AuditLogger::write('updated', $locked, $old, $this->snapshot($locked->fresh()), $actor);

            return $locked->fresh();
        });
    }

    public function delete(PurchaseRequest $request, User $actor): void
    {
        $this->requireRole($request->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can delete purchase requests.');
        $this->requireRequester($request, $actor);
        $this->requireState($request, ['draft'], 'deleted');

        AuditLogger::transaction(function () use ($request, $actor) {
            $locked = $this->lockRecord($request);
            $this->requireState($locked, ['draft'], 'deleted');
            $old = $this->snapshot($locked);
            $locked->delete(); // soft delete
            AuditLogger::write('deleted', $locked, $old, [], $actor);
        });
    }

    public function submit(PurchaseRequest $request, User $actor): PurchaseRequest
    {
        $this->requireRole($request->project, $actor, self::ENCODERS, 'Only a Research Assistant or Finance/Admin staff of this project can submit purchase requests.');
        $this->requireState($request, self::EDITABLE, 'submitted');

        if (! $request->supportingDocuments()->exists()) {
            throw ValidationException::withMessages([
                'documents' => ['Attach a quotation or invoice before submitting.'],
            ]);
        }

        $request = $this->moveRecord($request, 'submitted', $actor, 'submitted', self::EDITABLE, 'submitted', [
            'submitted_at' => now(),
            'return_reason' => null,
            'pl_reviewed_by' => null,
            'pl_reviewed_at' => null,
        ]);

        $this->notify($this->projectMembers($request->project_id, 'project-leader'), $request, 'submitted', $actor);

        return $request;
    }

    public function endorse(PurchaseRequest $request, User $actor): PurchaseRequest
    {
        $this->checkLeader($request, $actor, 'approve');
        $this->requireState($request, ['submitted'], 'approved');

        $request = $this->moveRecord($request, 'endorsed', $actor, 'endorsed', ['submitted'], 'approved', [
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ]);

        $this->notify($this->activeUser($request->requested_by), $request, 'endorsed', $actor);

        return $request;
    }

    public function returnToRequester(PurchaseRequest $request, string $reason, User $actor): PurchaseRequest
    {
        $this->checkLeader($request, $actor, 'return');
        $this->requireState($request, ['submitted'], 'returned');
        $reason = $this->requireReason($reason, 'A reason is required to return a purchase request.');

        $request = $this->moveRecord($request, 'returned', $actor, 'returned', ['submitted'], 'returned', [
            'return_reason' => $reason,
            'pl_reviewed_by' => $actor->id,
            'pl_reviewed_at' => now(),
        ], $reason);

        $this->notify($this->activeUser($request->requested_by), $request, 'returned', $actor, $reason);

        return $request;
    }

    protected function stageLabels(): array
    {
        return self::LABELS;
    }

    protected function noun(): string
    {
        return 'purchase request';
    }

    private function checkLeader(PurchaseRequest $request, User $actor, string $verb): void
    {
        $this->requireRole($request->project, $actor, ['project-leader'], "Only the Project Leader of this project can {$verb} purchase requests.");
        $this->requireNotSame($request->requested_by, $actor, 'You cannot approve or return a purchase request you made.');
    }

    private function requireRequester(PurchaseRequest $request, User $actor): void
    {
        if ((int) $request->requested_by !== (int) $actor->id) {
            abort(403, 'You can only delete purchase requests you made.');
        }
    }

    private function snapshot(PurchaseRequest $request): array
    {
        return $request->only(['project_id', 'lib_category_id', 'description', 'quantity', 'estimated_amount', 'workflow_status']);
    }

    private function notify(Collection $recipients, PurchaseRequest $request, string $event, User $actor, ?string $reason = null): void
    {
        $request->loadMissing('project');
        $what = $request->description . ' (' . $request->quantity . ' × ₱' . number_format((float) $request->estimated_amount, 2) . ' est.)';
        $because = $reason ? ' Reason: ' . $reason : '';

        [$title, $message] = match ($event) {
            'submitted' => ['Purchase request waiting for your approval', "{$actor->name} submitted a purchase request: {$what}."],
            'endorsed' => ['Purchase request approved', "{$actor->name} (Project Leader) approved your purchase request: {$what}."],
            'returned' => ['Purchase request returned to you', "{$actor->name} returned your purchase request: {$request->description}.{$because}"],
        };

        $this->notifyUsers($recipients, new WorkflowNotification([
            'kind' => 'purchase_request',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'purchase_request_id' => $request->id,
            'project_id' => $request->project_id,
            'project' => $request->project?->title,
            'actor' => $actor->name,
            'reason' => $reason,
            'link' => '/purchase-requests?project=' . $request->project_id . '&request=' . $request->id,
        ]), $actor);
    }
}

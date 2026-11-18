<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Document;
use App\Models\Project;
use App\Services\BudgetRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Budget requests from the Project Leader to the Program Leader. They are
 * approval requests; the Program Leader approves or returns them through
 * POST /api/program/approvals/{approval}/decision. A returned request is
 * resubmitted or withdrawn by its maker here.
 */
class BudgetRequestController extends Controller
{
    public function __construct(private BudgetRequestService $requests)
    {
    }

    /** GET /api/projects/{project}/budget-requests — this project's requests, newest first. */
    public function index(Project $project)
    {
        $rows = $project->approvalRequests()
            ->whereIn('type', array_values(BudgetRequestService::TYPES))
            ->with(['requestedBy', 'reviewedBy', 'withdrawnBy'])
            ->withCount('documents')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApprovalRequest $approval) => $this->row($approval))
            ->values();

        return response()->json(['budget_requests' => $rows]);
    }

    /**
     * POST /api/projects/{project}/budget-requests (JSON, or multipart for a
     * liquidation with files[]).
     */
    public function store(Request $request, Project $project)
    {
        $data = $request->validate(
            ['type' => ['required', Rule::in(array_keys(BudgetRequestService::TYPES))]] + $this->fieldRules(),
            $this->fieldMessages()
        );

        $approval = $this->requests->create($project, $data, $request->file('files', []), $request->user());

        return response()->json(['message' => 'Request sent to the Program Leader.', 'budget_request' => $this->row($approval, $request->user())], 201);
    }

    /**
     * POST /api/budget-requests/{approval}/resubmit (JSON, or multipart with
     * files[]): edit a returned request and send it back to the Program
     * Leader. The type stays; remove_document_ids[] deletes the maker's
     * documents.
     */
    public function resubmit(Request $request, ApprovalRequest $approval)
    {
        $type = array_search($approval->type, BudgetRequestService::TYPES, true);
        abort_if($type === false, 404, 'Budget request not found.');
        $request->merge(['type' => $type]);

        $data = $request->validate($this->fieldRules() + [
            'remove_document_ids' => ['nullable', 'array', 'max:50'],
            'remove_document_ids.*' => ['integer'],
        ], $this->fieldMessages());

        $approval = $this->requests->resubmit(
            $approval,
            $data,
            $request->file('files', []),
            $data['remove_document_ids'] ?? [],
            $request->user()
        );

        return response()->json(['message' => 'Request resubmitted to the Program Leader.', 'budget_request' => $this->row($approval, $request->user())]);
    }

    /** POST /api/budget-requests/{approval}/withdraw — the maker closes a returned request. */
    public function withdraw(Request $request, ApprovalRequest $approval)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $approval = $this->requests->withdraw($approval, $data['reason'] ?? null, $request->user());

        return response()->json(['message' => 'Request withdrawn.', 'budget_request' => $this->row($approval, $request->user())]);
    }

    /** Fields of a create or a resubmit (`type` is set from the request on a resubmit). */
    private function fieldRules(): array
    {
        return [
            'justification' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
            'project_tranche_id' => ['nullable', 'integer', 'required_if:type,fund_release'],
            'from_lib_category_id' => ['nullable', 'integer', 'required_if:type,realignment'],
            'to_lib_category_id' => ['nullable', 'integer', 'required_if:type,realignment'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999.99', 'required_if:type,realignment', 'required_if:type,liquidation'],
            'document_type' => ['nullable', Rule::in(Document::TYPES)],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => DocumentController::fileRules(),
        ];
    }

    private function fieldMessages(): array
    {
        return DocumentController::fileMessages('files.*') + [
            'justification.required' => 'A justification is required.',
            'justification.not_regex' => 'A justification is required.',
            'project_tranche_id.required_if' => 'Choose the release to request.',
        ];
    }

    private function row(ApprovalRequest $approval, $viewer = null): array
    {
        $approval->loadMissing(['requestedBy', 'reviewedBy', 'withdrawnBy', 'tranche']);
        $viewer ??= request()->user();
        $mine = $viewer && (int) $approval->requested_by === (int) $viewer->id;
        $payload = $approval->payload ?? [];
        $type = array_search($approval->type, BudgetRequestService::TYPES, true) ?: $approval->type;

        return [
            'id' => $approval->id,
            'project_id' => $approval->project_id,
            'type' => $type,
            'type_label' => $approval->typeLabel(),
            'title' => $approval->displayTitle(),
            'amount' => $approval->amount !== null ? (float) $approval->amount : null,
            'payload' => $approval->payload,
            'project_tranche_id' => $approval->project_tranche_id,
            'justification' => $approval->justification,
            // pending | approved | returned | withdrawn (a stored "rejected" is a return).
            'status' => $approval->displayStatus(),
            'status_label' => $approval->statusLabel(),
            'review_notes' => $approval->review_notes,
            'last_return_reason' => $approval->last_return_reason,
            'resubmitted_at' => $approval->resubmitted_at?->toIso8601String(),
            'withdrawn_at' => $approval->withdrawn_at?->toIso8601String(),
            'withdrawn_by' => $approval->withdrawnBy?->name,
            'can_resubmit' => $mine && $approval->isReturned(),
            'can_withdraw' => $mine && $approval->isReturned(),
            // Values the "Edit and resubmit" form starts from (the maker only).
            'edit' => $mine && $approval->isReturned() ? [
                'type' => $type,
                'project_tranche_id' => $approval->project_tranche_id !== null ? (int) $approval->project_tranche_id : null,
                'from_lib_category_id' => isset($payload['from_lib_category_id']) ? (int) $payload['from_lib_category_id'] : null,
                'to_lib_category_id' => isset($payload['to_lib_category_id']) ? (int) $payload['to_lib_category_id'] : null,
                'amount' => $approval->amount !== null ? (float) $approval->amount : null,
                'title' => $approval->title,
                'description' => $approval->description,
                'justification' => $approval->justification,
            ] : null,
            'documents_count' => (int) ($approval->documents_count ?? $approval->documents()->count()),
            'requested_by' => $approval->requestedBy ? ['id' => $approval->requestedBy->id, 'name' => $approval->requestedBy->name] : null,
            'reviewed_by' => $approval->reviewedBy?->name ?? $approval->reviewed_by_name,
            'created_at' => $approval->created_at?->toIso8601String(),
            'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
        ];
    }
}

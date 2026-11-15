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
 * approval requests; the Program Leader decides on them through
 * POST /api/program/approvals/{approval}/decision.
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
            ->with(['requestedBy', 'reviewedBy'])
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
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(BudgetRequestService::TYPES))],
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
        ], DocumentController::fileMessages('files.*') + [
            'justification.required' => 'A justification is required.',
            'justification.not_regex' => 'A justification is required.',
            'project_tranche_id.required_if' => 'Choose the tranche to release.',
        ]);

        $approval = $this->requests->create($project, $data, $request->file('files', []), $request->user());

        return response()->json(['message' => 'Request sent to the Program Leader.', 'budget_request' => $this->row($approval)], 201);
    }

    private function row(ApprovalRequest $approval): array
    {
        $approval->loadMissing(['requestedBy', 'reviewedBy']);

        return [
            'id' => $approval->id,
            'project_id' => $approval->project_id,
            'type' => array_search($approval->type, BudgetRequestService::TYPES, true) ?: $approval->type,
            'type_label' => $approval->typeLabel(),
            'title' => $approval->title,
            'amount' => $approval->amount !== null ? (float) $approval->amount : null,
            'payload' => $approval->payload,
            'project_tranche_id' => $approval->project_tranche_id,
            'justification' => $approval->justification,
            'status' => $approval->status,
            'review_notes' => $approval->review_notes,
            'documents_count' => (int) ($approval->documents_count ?? $approval->documents()->count()),
            'requested_by' => $approval->requestedBy ? ['id' => $approval->requestedBy->id, 'name' => $approval->requestedBy->name] : null,
            'reviewed_by' => $approval->reviewedBy?->name ?? $approval->reviewed_by_name,
            'created_at' => $approval->created_at?->toIso8601String(),
            'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
        ];
    }
}

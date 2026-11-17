<?php

namespace App\Http\Controllers;

use App\Models\LibCategory;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase request API (through PurchaseRequestService; project-scoped). */
class PurchaseRequestController extends Controller
{
    public function __construct(private PurchaseRequestService $requests)
    {
    }

    /** GET /api/projects/{project}/purchase-requests?workflow_status= */
    public function index(Request $request, Project $project)
    {
        $data = $request->validate([
            'workflow_status' => ['nullable', Rule::in(array_keys(PurchaseRequestService::LABELS))],
        ]);

        $rows = $project->purchaseRequests()
            ->with(['libCategory', 'requestedBy', 'plReviewedBy'])
            ->withCount('documents')
            ->when($data['workflow_status'] ?? null, fn ($q, $status) => $q->where('workflow_status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseRequest $row) => $this->row($row))
            ->values();

        return response()->json(['purchase_requests' => $rows]);
    }

    public function store(Request $request, Project $project)
    {
        $row = $this->requests->create($project, $this->validated($request, $project), $request->user());

        return response()->json(['message' => 'Purchase request saved as a draft.', 'purchase_request' => $this->row($row)], 201);
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest)
    {
        $row = $this->requests->update($purchaseRequest, $this->validated($request, $purchaseRequest->project, true), $request->user());

        return response()->json(['message' => 'Purchase request updated.', 'purchase_request' => $this->row($row)]);
    }

    public function destroy(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->requests->delete($purchaseRequest, $request->user());

        return response()->json(['message' => 'Purchase request deleted.']);
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest)
    {
        return response()->json(['message' => 'Purchase request submitted to the Project Leader.', 'purchase_request' => $this->row($this->requests->submit($purchaseRequest, $request->user()))]);
    }

    public function endorse(Request $request, PurchaseRequest $purchaseRequest)
    {
        return response()->json(['message' => 'Purchase request approved by the Project Leader.', 'purchase_request' => $this->row($this->requests->endorse($purchaseRequest, $request->user()))]);
    }

    public function returnRequest(Request $request, PurchaseRequest $purchaseRequest)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['message' => 'Purchase request returned.', 'purchase_request' => $this->row($this->requests->returnToRequester($purchaseRequest, $data['reason'], $request->user()))]);
    }

    /** POST /api/purchase-requests/{id}/cancel { reason } — Project Leader or requester. */
    public function cancel(Request $request, PurchaseRequest $purchaseRequest)
    {
        // Who may cancel is checked before the reason.
        $reason = (string) $request->input('reason', '');

        return response()->json(['message' => 'Purchase request cancelled.', 'purchase_request' => $this->row($this->requests->cancel($purchaseRequest, $reason, $request->user()))]);
    }

    private function validated(Request $request, Project $project, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'lib_category_id' => [$required, 'integer', Rule::exists((new LibCategory())->getTable(), 'id')->where('project_id', $project->id)],
            'description' => [$required, 'string', 'max:500'],
            'quantity' => [$required, 'integer', 'min:1', 'max:1000000'],
            'estimated_amount' => [$required, 'numeric', 'gt:0', 'max:9999999999999.99'],
            'supplier' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], ['lib_category_id.exists' => 'Choose a line item of this project.']);
    }

    private function row(PurchaseRequest $row): array
    {
        $row->loadMissing(['libCategory', 'requestedBy', 'plReviewedBy', 'fulfilledBy', 'cancelledBy', 'expense']);
        $person = fn ($user) => $user ? ['id' => $user->id, 'name' => $user->name] : null;

        return [
            'id' => $row->id,
            'project_id' => $row->project_id,
            'lib_category_id' => $row->lib_category_id,
            'line_item' => $row->libCategory?->line_item_name,
            'description' => $row->description,
            'quantity' => $row->quantity,
            'estimated_amount' => round((float) $row->estimated_amount, 2),
            'supplier' => $row->supplier,
            'workflow_status' => $row->workflow_status,
            'workflow_label' => PurchaseRequestService::LABELS[$row->workflow_status] ?? $row->workflow_status,
            'return_reason' => $row->return_reason,
            'documents_count' => (int) ($row->documents_count ?? $row->documents()->count()),
            'requested_by' => $person($row->requestedBy),
            'pl_reviewed_by' => $person($row->plReviewedBy),
            'submitted_at' => $row->submitted_at?->toIso8601String(),
            'pl_reviewed_at' => $row->pl_reviewed_at?->toIso8601String(),
            'expense_id' => $row->expense?->id,
            'fulfilled_by' => $person($row->fulfilledBy),
            'fulfilled_at' => $row->fulfilled_at?->toIso8601String(),
            'cancelled_by' => $person($row->cancelledBy),
            'cancelled_at' => $row->cancelled_at?->toIso8601String(),
            'cancel_reason' => $row->cancel_reason,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}

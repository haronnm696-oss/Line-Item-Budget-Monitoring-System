<?php

namespace App\Http\Controllers;

use App\Support\MoneyInput;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Rules\DateInRange;
use App\Support\PurchaseRequestItems;
use App\Services\PurchaseRequestFormService;
use App\Services\PurchaseRequestService;
use App\Support\Csv;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase request API (through PurchaseRequestService; project-scoped). */
class PurchaseRequestController extends Controller
{
    public function __construct(private PurchaseRequestService $requests, private PurchaseRequestFormService $form)
    {
    }

    /**
     * GET /api/purchase-requests/{id}/form — the request in MSU's Purchase
     * Request form (the PDF print view). The project's Research Assistant,
     * Project Leader, Finance/Admin and the Program Leader; Researchers 403.
     */
    public function form(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->requirePrintAccess($request, $purchaseRequest);

        return response()->json($this->form->build($purchaseRequest));
    }

    /** GET /api/purchase-requests/{id}/form/csv — the same form as CSV (plain numbers). */
    public function formCsv(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->requirePrintAccess($request, $purchaseRequest);
        $doc = $this->form->build($purchaseRequest);
        $filename = Str::slug($doc['title']) . '.csv';

        return response(Csv::render($this->form->csvRows($doc)), 200, Csv::headers($filename));
    }

    private function requirePrintAccess(Request $request, PurchaseRequest $purchaseRequest): void
    {
        abort_unless(
            PurchaseRequestFormService::canPrint($request->user(), $purchaseRequest),
            403,
            'Only the Research Assistant, Project Leader and Finance/Admin staff of this project, or the Program Leader, can download purchase requests.'
        );
    }

    /** GET /api/projects/{project}/purchase-requests?workflow_status= */
    public function index(Request $request, Project $project)
    {
        $data = $request->validate([
            'workflow_status' => ['nullable', Rule::in(array_keys(PurchaseRequestService::LABELS))],
        ]);

        $rows = $project->purchaseRequests()
            ->with(['libCategory', 'requestedBy', 'plReviewedBy', 'items.libCategory', 'expenses'])
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
        $row = $this->requests->update($purchaseRequest, $this->validated($request, $purchaseRequest->project, $purchaseRequest), $request->user());

        return response()->json(['message' => 'Purchase request updated.', 'purchase_request' => $this->row($row)]);
    }

    public function destroy(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->requests->delete($purchaseRequest, $request->user());

        return response()->json(['message' => 'Purchase request deleted.']);
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest)
    {
        $submitted = $this->requests->submit($purchaseRequest, $request->user());
        // Made by the Project Leader: no Project Leader step.
        $message = $submitted->workflow_status === 'endorsed'
            ? 'Purchase request submitted. It was made by the Project Leader, so it skips the Project Leader approval.'
            : 'Purchase request submitted to the Project Leader.';

        return response()->json(['message' => $message, 'purchase_request' => $this->row($submitted)]);
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

    /**
     * PATCH /api/purchase-requests/{id}/numbers { pr_no, pr_date, sai_no,
     * sai_date, alobs_no, alobs_date } — Finance/Admin, approved or fulfilled.
     */
    public function numbers(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! $purchaseRequest->project || ! $request->user()->hasRoleOn($purchaseRequest->project, 'finance-admin')) {
            abort(403, 'Only Finance/Admin staff of this project can set the PR, SAI and ALOBS numbers.');
        }
        $data = $request->validate([
            'pr_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pr_date' => ['bail', 'sometimes', 'nullable', new DateInRange(), 'date'],
            'sai_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sai_date' => ['bail', 'sometimes', 'nullable', new DateInRange(), 'date'],
            'alobs_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'alobs_date' => ['bail', 'sometimes', 'nullable', new DateInRange(), 'date'],
        ]);
        foreach (['pr_no', 'sai_no', 'alobs_no'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim((string) $data[$field]) === '' ? null : trim($data[$field]);
            }
        }

        return response()->json([
            'message' => 'PR, SAI and ALOBS details saved.',
            'purchase_request' => $this->row($this->requests->setNumbers($purchaseRequest, $data, $request->user())),
        ]);
    }

    /**
     * New payload: purpose (or description), supplier, funding_source and
     * items[] { lib_category_id, quantity, unit, description, unit_cost }.
     * The older single-item payload (lib_category_id, description, quantity,
     * estimated_amount) is still accepted and becomes one "lot" item; on an
     * edit it changes the request's only item.
     */
    private function validated(Request $request, Project $project, ?PurchaseRequest $existing = null): array
    {
        $partial = $existing !== null;
        if ($request->has('purpose')) {
            $request->merge(['description' => $request->input('purpose')]);
        }
        $lineRule = fn () => Rule::exists((new LibCategory())->getTable(), 'id')->where('project_id', $project->id);
        $legacy = ! $request->has('items') && ($request->hasAny(['lib_category_id', 'quantity', 'estimated_amount']) || ! $partial);

        $rules = [
            'description' => [$partial ? 'sometimes' : 'required', 'string', 'max:500'],
            'supplier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'funding_source' => ['sometimes', 'nullable', Rule::in(LibCategory::SOURCES)],
        ];
        if ($legacy) {
            $required = $partial ? 'sometimes' : 'required';
            $rules += [
                'lib_category_id' => [$required, 'integer', $lineRule()],
                'quantity' => [$required, 'integer', 'min:1', 'max:1000000'],
                'estimated_amount' => array_merge([$required, 'gt:0'], MoneyInput::RULES),
            ];
        } else {
            $rules += [
                'items' => [$partial ? 'sometimes' : 'required', 'array', 'min:1', 'max:100'],
                'items.*' => ['array'],
                'items.*.lib_category_id' => ['required', 'integer', $lineRule()],
                'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
                'items.*.unit' => ['required', Rule::in(PurchaseRequestItem::UNITS)],
                'items.*.description' => ['required', 'string', 'max:500'],
                'items.*.unit_cost' => array_merge(['required', 'gt:0'], MoneyInput::RULES),
            ];
        }

        $data = $request->validate($rules, [
            'lib_category_id.exists' => 'Choose a line item of this project.',
            'items.required' => 'Add at least one item.',
            'items.min' => 'Add at least one item.',
            'items.*.lib_category_id.required' => 'Choose a line item.',
            'items.*.lib_category_id.exists' => 'Choose a line item of this project.',
            'items.*.quantity.*' => 'Enter a whole number of at least 1.',
            'items.*.unit.*' => 'Choose a unit.',
            'items.*.description.required' => 'Describe the item.',
            'items.*.unit_cost.required' => 'Enter the estimated unit cost.',
            'items.*.unit_cost.gt' => 'Enter a unit cost greater than zero.',
            'items.*.unit_cost.regex' => MoneyInput::DECIMALS_MESSAGE,
        ], ['description' => 'purpose']);

        if ($legacy) {
            $data = $this->legacyItem($data, $existing);
        } elseif (array_key_exists('items', $data)) {
            $data['items'] = array_map(fn ($item) => [
                'lib_category_id' => (int) $item['lib_category_id'],
                'quantity' => (int) $item['quantity'],
                'unit' => $item['unit'],
                'description' => trim($item['description']),
                'unit_cost' => round((float) $item['unit_cost'], 2),
            ], array_values($data['items']));
        }

        if (isset($data['items'])) {
            $total = array_sum(array_map(fn ($item) => isset($item['keep_cost']) ? $item['estimated_cost'] : PurchaseRequestService::cost($item['quantity'], $item['unit_cost']), $data['items']));
            if ($total > 9999999999999.99) {
                throw ValidationException::withMessages(['items' => ['The total is too large.']]);
            }
        }

        return $data;
    }

    /** The older single-item payload as one "lot" item (unit cost = amount / quantity). */
    private function legacyItem(array $data, ?PurchaseRequest $existing): array
    {
        $given = array_intersect_key($data, array_flip(['lib_category_id', 'quantity', 'estimated_amount']));
        unset($data['lib_category_id'], $data['quantity'], $data['estimated_amount']);
        if ($existing && ! $given) {
            return $data;
        }
        $current = $existing?->lineItems();
        if ($current && $current->count() !== 1) {
            throw ValidationException::withMessages(['items' => ['This purchase request has several items; edit the items instead.']]);
        }
        $item = $current?->first();
        $quantity = (int) ($given['quantity'] ?? $item?->quantity ?? 1);
        $amount = round((float) ($given['estimated_amount'] ?? $item?->estimated_cost ?? 0), 2);
        $data['items'] = [[
            'lib_category_id' => (int) ($given['lib_category_id'] ?? $item?->lib_category_id),
            'quantity' => $quantity,
            'unit' => $item?->unit ?? 'lot',
            'description' => $existing ? (string) $item?->description : (string) $data['description'],
            'unit_cost' => round($amount / max(1, $quantity), 2),
            'estimated_cost' => $amount,
            'keep_cost' => true,
        ]];

        return $data;
    }

    private function row(PurchaseRequest $row): array
    {
        $row->loadMissing(['libCategory', 'requestedBy', 'plReviewedBy', 'fulfilledBy', 'cancelledBy', 'expense', 'expenses', 'items.libCategory']);
        $person = fn ($user) => $user ? ['id' => $user->id, 'name' => $user->name] : null;
        $items = PurchaseRequestItems::rows($row);

        return [
            'id' => $row->id,
            'project_id' => $row->project_id,
            'lib_category_id' => $row->lib_category_id,
            'line_item' => PurchaseRequestItems::lineSummary($row),
            'funding_source' => $row->funding_source ?: LibCategory::SOURCE_FUNDER,
            'description' => $row->description,
            'purpose' => $row->description,
            'quantity' => $row->quantity,
            'estimated_amount' => round((float) $row->estimated_amount, 2),
            'total' => round((float) $row->estimated_amount, 2),
            'items' => $items,
            'supplier' => $row->supplier,
            'pr_no' => $row->pr_no,
            'pr_date' => $row->pr_date?->toDateString(),
            'sai_no' => $row->sai_no,
            'sai_date' => $row->sai_date?->toDateString(),
            'alobs_no' => $row->alobs_no,
            'alobs_date' => $row->alobs_date?->toDateString(),
            'workflow_status' => $row->workflow_status,
            'workflow_label' => PurchaseRequestService::label($row),
            // full | partial | none: how far the items are paid (Partly fulfilled, Cancelled (partly fulfilled)).
            'fulfilment' => $row->fulfilment(),
            'return_reason' => $row->return_reason,
            'documents_count' => (int) ($row->documents_count ?? $row->documents()->count()),
            'requested_by' => $person($row->requestedBy),
            'pl_reviewed_by' => $person($row->plReviewedBy),
            'submitted_at' => $row->submitted_at?->toIso8601String(),
            'pl_reviewed_at' => $row->pl_reviewed_at?->toIso8601String(),
            'expense_id' => $row->expense?->id,
            'expense_ids' => $row->expenses->pluck('id')->values()->all(),
            'fulfilled_by' => $person($row->fulfilledBy),
            'fulfilled_at' => $row->fulfilled_at?->toIso8601String(),
            'cancelled_by' => $person($row->cancelledBy),
            'cancelled_at' => $row->cancelled_at?->toIso8601String(),
            'cancel_reason' => $row->cancel_reason,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}

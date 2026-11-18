<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Services\ExpenseWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Expense workflow API. Every change goes through ExpenseWorkflowService;
 * project scoping is enforced by the project.access middleware.
 */
class ExpenseController extends Controller
{
    public function __construct(private ExpenseWorkflowService $workflow)
    {
    }

    /** GET /api/projects/{project}/expenses?workflow_status= */
    public function index(Request $request, Project $project)
    {
        $data = $request->validate([
            'workflow_status' => ['nullable', Rule::in(array_keys(ExpenseWorkflowService::LABELS))],
        ]);

        $expenses = $project->expenses()
            ->with(['libCategory', 'recordedBy', 'plReviewedBy', 'verifiedBy'])
            ->withCount('receipts')
            ->when($data['workflow_status'] ?? null, fn ($q, $status) => $q->where('workflow_status', $status))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Expense $expense) => $this->row($expense))
            ->values();

        return response()->json(['expenses' => $expenses]);
    }

    public function store(Request $request, Project $project)
    {
        $data = $this->validated($request, $project);
        $expense = $this->workflow->create($project, $data, $request->user());

        return response()->json(['message' => 'Expense saved as a draft.', 'expense' => $this->row($expense)], 201);
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $this->validated($request, $expense->project, true);
        $expense = $this->workflow->update($expense, $data, $request->user());

        return response()->json(['message' => 'Expense updated.', 'expense' => $this->row($expense)]);
    }

    public function submit(Request $request, Expense $expense)
    {
        $expense = $this->workflow->submit($expense, $request->user());

        return response()->json(['message' => 'Expense submitted to the Project Leader.', 'expense' => $this->row($expense)]);
    }

    public function endorse(Request $request, Expense $expense)
    {
        $expense = $this->workflow->endorse($expense, $request->user());

        return response()->json(['message' => 'Expense approved by the Project Leader.', 'expense' => $this->row($expense)]);
    }

    public function returnExpense(Request $request, Expense $expense)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $expense = $this->workflow->returnToEncoder($expense, $data['reason'], $request->user());

        return response()->json(['message' => 'Expense returned.', 'expense' => $this->row($expense)]);
    }

    public function verify(Request $request, Expense $expense)
    {
        $expense = $this->workflow->verify($expense, $request->user());

        return response()->json(['message' => 'Expense verified.', 'expense' => $this->row($expense)]);
    }

    public function reject(Request $request, Expense $expense)
    {
        // Role and maker-checker are checked before the reason, so a person
        // who may not act is told so first.
        $reason = (string) $request->input('reason', '');
        $expense = $this->workflow->reject($expense, $reason, $request->user());

        return response()->json(['message' => 'Expense rejected and sent back.', 'expense' => $this->row($expense)]);
    }

    public function destroy(Request $request, Expense $expense)
    {
        $this->workflow->delete($expense, $request->user());

        return response()->json(['message' => 'Expense deleted.']);
    }

    private function validated(Request $request, Project $project, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'lib_category_id' => [$required, 'integer', Rule::exists((new LibCategory())->getTable(), 'id')->where('project_id', $project->id)],
            'description' => [$required, 'string', 'max:500'],
            'amount' => [$required, 'numeric', 'gt:0', 'max:9999999999999.99'],
            'expense_date' => [$required, 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'payee' => ['nullable', 'string', 'max:255'],
            // An approved purchase request of this project that the expense fulfils.
            'purchase_request_id' => ['nullable', 'integer', Rule::exists((new PurchaseRequest())->getTable(), 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
        ], [
            'lib_category_id.exists' => 'Choose a line item of this project.',
            'purchase_request_id.exists' => 'Choose a purchase request of this project.',
        ]);
    }

    private function row(Expense $expense): array
    {
        $expense->loadMissing(['libCategory', 'recordedBy', 'plReviewedBy', 'verifiedBy']);
        $receipts = $expense->receipts_count ?? $expense->receipts()->count();

        return [
            'id' => $expense->id,
            'project_id' => $expense->project_id,
            'lib_category_id' => $expense->lib_category_id,
            'purchase_request_id' => $expense->purchase_request_id,
            'line_item' => $expense->libCategory?->line_item_name,
            'description' => $expense->description,
            'payee' => $expense->payee,
            'reference_no' => $expense->reference_no,
            'amount' => round((float) $expense->amount, 2),
            'expense_date' => $expense->expense_date?->toDateString(),
            'status' => $expense->status,
            'workflow_status' => $expense->workflow_status,
            'workflow_label' => ExpenseWorkflowService::label($expense),
            'return_reason' => $expense->return_reason,
            'receipts_count' => (int) $receipts,
            'recorded_by' => $expense->recordedBy ? ['id' => $expense->recordedBy->id, 'name' => $expense->recordedBy->name] : null,
            // Maker only: a returned expense its recorder may edit and resubmit.
            'can_resubmit' => $expense->workflow_status === 'returned' && auth()->id() !== null
                && (int) $expense->recorded_by === (int) auth()->id(),
            'pl_reviewed_by' => $expense->plReviewedBy ? ['id' => $expense->plReviewedBy->id, 'name' => $expense->plReviewedBy->name] : null,
            'verified_by' => $expense->verifiedBy ? ['id' => $expense->verifiedBy->id, 'name' => $expense->verifiedBy->name] : null,
            'submitted_at' => $expense->submitted_at?->toIso8601String(),
            'pl_reviewed_at' => $expense->pl_reviewed_at?->toIso8601String(),
            'verified_at' => $expense->verified_at?->toIso8601String(),
        ];
    }
}

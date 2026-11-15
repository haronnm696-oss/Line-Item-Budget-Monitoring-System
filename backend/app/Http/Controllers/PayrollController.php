<?php

namespace App\Http\Controllers;

use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\Project;
use App\Services\PayrollCalculator;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payroll API. Every change goes through PayrollService; project scoping is
 * enforced by the project.access middleware.
 */
class PayrollController extends Controller
{
    private const MONTH = ['regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

    public function __construct(private PayrollService $payroll, private PayrollCalculator $calculator)
    {
    }

    /** GET /api/projects/{project}/payroll?month=YYYY-MM (default: this month, Manila). */
    public function index(Request $request, Project $project)
    {
        $data = $request->validate(['month' => array_merge(['nullable'], self::MONTH)], $this->monthMessage());
        [$year, $month] = $this->parts($data['month'] ?? now('Asia/Manila')->format('Y-m'));

        return response()->json($this->run($project, $year, $month));
    }

    /** POST /api/projects/{project}/payroll { month, lib_category_id } — create and compute the month. */
    public function store(Request $request, Project $project)
    {
        $data = $request->validate([
            'month' => array_merge(['required'], self::MONTH),
            'lib_category_id' => ['required', 'integer', $this->psLineItem($project)],
        ], $this->monthMessage() + ['lib_category_id.exists' => 'Choose a Personal Services (PS) line item of this project.']);
        [$year, $month] = $this->parts($data['month']);

        $created = $this->payroll->createMonth($project, $year, $month, LibCategory::findOrFail($data['lib_category_id']), $request->user());

        return response()->json([
            'message' => 'Payroll created for ' . $created->count() . ' ' . ($created->count() === 1 ? 'person' : 'people') . '.',
        ] + $this->run($project, $year, $month), 201);
    }

    /** POST /api/projects/{project}/payroll/compute { month } — recompute draft / returned rows. */
    public function compute(Request $request, Project $project)
    {
        $data = $request->validate(['month' => array_merge(['required'], self::MONTH)], $this->monthMessage());
        [$year, $month] = $this->parts($data['month']);

        $rows = $this->payroll->computeMonth($project, $year, $month, $request->user());

        return response()->json(['message' => 'Payroll computed for ' . $rows->count() . ' ' . ($rows->count() === 1 ? 'entry' : 'entries') . '.'] + $this->run($project, $year, $month));
    }

    public function update(Request $request, Payroll $payroll)
    {
        $data = $request->validate([
            'basic_rate' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'allowance' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999.99'],
            'lib_category_id' => ['sometimes', 'integer', $this->psLineItem($payroll->project)],
        ], ['lib_category_id.exists' => 'Choose a Personal Services (PS) line item of this project.']);

        return response()->json(['message' => 'Payroll updated.', 'payroll' => $this->row($this->payroll->update($payroll, $data, $request->user()))]);
    }

    public function submit(Request $request, Payroll $payroll)
    {
        return response()->json(['message' => 'Payroll submitted to the Project Leader.', 'payroll' => $this->row($this->payroll->submit($payroll, $request->user()))]);
    }

    public function endorse(Request $request, Payroll $payroll)
    {
        return response()->json(['message' => 'Payroll approved by the Project Leader.', 'payroll' => $this->row($this->payroll->endorse($payroll, $request->user()))]);
    }

    public function returnPayroll(Request $request, Payroll $payroll)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['message' => 'Payroll returned.', 'payroll' => $this->row($this->payroll->returnToEncoder($payroll, $data['reason'], $request->user()))]);
    }

    public function verify(Request $request, Payroll $payroll)
    {
        return response()->json(['message' => 'Payroll verified.', 'payroll' => $this->row($this->payroll->verify($payroll, $request->user()))]);
    }

    public function reject(Request $request, Payroll $payroll)
    {
        // Role and maker-checker first, then the reason.
        $reason = (string) $request->input('reason', '');

        return response()->json(['message' => 'Payroll rejected and sent back.', 'payroll' => $this->row($this->payroll->reject($payroll, $reason, $request->user()))]);
    }

    // ---------------------------------------------------------------- views

    private function run(Project $project, int $year, int $month): array
    {
        $rows = Payroll::query()
            ->where('project_id', $project->id)->where('pay_year', $year)->where('pay_month', $month)
            ->with(['personnel', 'libCategory', 'deductions', 'computedBy', 'plReviewedBy', 'verifiedBy'])
            ->withCount('documents')
            ->orderBy('id')
            ->get();

        $breakdown = $rows->flatMap->deductions
            ->groupBy('deduction_type')
            ->map(fn ($items, $type) => ['type' => $type, 'amount' => round((float) $items->sum('amount'), 2)])
            ->values();

        return [
            'month' => sprintf('%04d-%02d', $year, $month),
            'month_label' => PayrollService::monthLabel($year, $month),
            'rows' => $rows->map(fn (Payroll $row) => $this->row($row))->values(),
            'deductionBreakdown' => $breakdown,
            'totals' => [
                'gross' => round((float) $rows->sum('gross_pay'), 2),
                'deductions' => round((float) $rows->sum('total_deductions'), 2),
                'net' => round((float) $rows->sum('net_pay'), 2),
            ],
            // Deduction rules whose rates are not set yet (config/payroll.php).
            'unset_rules' => $this->calculator->unsetRules(),
            'rated_members' => $this->payroll->ratedMembers($project)->count(),
            'ps_line_items' => $project->libCategories()->where('category_type', 'PS')->orderBy('id')->get()
                ->map(fn (LibCategory $item) => ['id' => $item->id, 'name' => $item->line_item_name])->values(),
        ];
    }

    private function row(Payroll $payroll): array
    {
        $payroll->loadMissing(['personnel', 'libCategory', 'deductions', 'computedBy', 'plReviewedBy', 'verifiedBy']);
        $person = fn ($user) => $user ? ['id' => $user->id, 'name' => $user->name] : null;

        return [
            'id' => $payroll->id,
            'project_id' => $payroll->project_id,
            'month' => $payroll->monthKey(),
            'personnel' => $payroll->personnel ? ['id' => $payroll->personnel->id, 'name' => $payroll->personnel->name, 'position' => $payroll->personnel->position] : null,
            'name' => $payroll->personnel?->name,
            'position' => $payroll->personnel?->position,
            'lib_category_id' => $payroll->lib_category_id,
            'category' => $payroll->libCategory?->line_item_name,
            'basic' => round((float) $payroll->basic_rate, 2),
            'allowance' => round((float) $payroll->allowance, 2),
            'gross' => round((float) $payroll->gross_pay, 2),
            'deductions' => round((float) $payroll->total_deductions, 2),
            'deduction_items' => $payroll->deductions->map(fn ($d) => ['type' => $d->deduction_type, 'amount' => round((float) $d->amount, 2)])->values(),
            'net' => round((float) $payroll->net_pay, 2),
            'status' => $payroll->status,
            'workflow_status' => $payroll->workflow_status,
            'workflow_label' => PayrollService::LABELS[$payroll->workflow_status] ?? $payroll->workflow_status,
            'return_reason' => $payroll->return_reason,
            'documents_count' => (int) ($payroll->documents_count ?? $payroll->documents()->count()),
            'computed_by' => $person($payroll->computedBy),
            'pl_reviewed_by' => $person($payroll->plReviewedBy),
            'verified_by' => $person($payroll->verifiedBy),
            'submitted_at' => $payroll->submitted_at?->toIso8601String(),
            'verified_at' => $payroll->verified_at?->toIso8601String(),
        ];
    }

    private function psLineItem(Project $project)
    {
        return Rule::exists((new LibCategory())->getTable(), 'id')
            ->where('project_id', $project->id)
            ->where('category_type', 'PS');
    }

    private function parts(string $month): array
    {
        return [(int) substr($month, 0, 4), (int) substr($month, 5, 2)];
    }

    private function monthMessage(): array
    {
        return ['month.regex' => 'Use a month in the form YYYY-MM.'];
    }
}

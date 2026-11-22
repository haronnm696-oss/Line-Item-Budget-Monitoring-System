<?php

namespace App\Http\Controllers;

use App\Support\MoneyInput;
use App\Rules\DateInRange;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payroll API. A batch covers a pay period of consecutive months (one month
 * or a quarter); ?month= is any month of it. Rows are created / computed /
 * edited per person; submit, approve / return and verify / reject act on the
 * batch. Project honoraria have no deductions and no allowances (net =
 * gross). Every change goes through PayrollService; project scoping is
 * enforced by the project.access middleware.
 */
class PayrollController extends Controller
{
    private const MONTH = ['bail', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

    public function __construct(private PayrollService $payroll)
    {
    }

    /**
     * GET /api/projects/{project}/payroll?month=YYYY-MM[&to=YYYY-MM]
     * The batch whose period includes `month`; with no batch there, the
     * period month..to (default: one month) as it would be prepared.
     */
    public function index(Request $request, Project $project)
    {
        abort_unless($request->user()->canSeePersonnelPay($project), 403, 'Payroll shows pay per person and is not available to Researchers.');
        $data = $request->validate([
            'month' => array_merge(['nullable'], self::MONTH, [new DateInRange()]),
            'to' => array_merge(['nullable'], self::MONTH, [new DateInRange()]),
        ], $this->monthMessage());
        $from = $this->first($data['month'] ?? now('Asia/Manila')->format('Y-m'));
        $to = isset($data['to']) ? $this->first($data['to']) : $from;

        return response()->json($this->run($project, $from, $to->lt($from) ? $from : $to));
    }

    /** POST /api/projects/{project}/payroll { month, to?, lib_category_id } — create and compute the period. */
    public function store(Request $request, Project $project)
    {
        $data = $request->validate([
            'month' => array_merge(['required'], self::MONTH, [new DateInRange()]),
            'to' => array_merge(['nullable'], self::MONTH, [new DateInRange()]),
            'lib_category_id' => ['required', 'integer', $this->psLineItem($project)],
        ], $this->monthMessage() + ['lib_category_id.exists' => 'Choose a Personal Services (PS) line item of this project.']);
        $from = $this->first($data['month']);
        $to = isset($data['to']) ? $this->first($data['to']) : $from;

        $created = $this->payroll->createPeriod($project, $from, $to, LibCategory::findOrFail($data['lib_category_id']), $request->user());

        return response()->json([
            'message' => 'Payroll created for ' . $created->count() . ' ' . ($created->count() === 1 ? 'person' : 'people') . '.' . $this->notIncludedText($project, $from, $to),
        ] + $this->run($project, $from, $to), 201);
    }

    /** POST /api/projects/{project}/payroll/compute { month } — recompute the draft / returned batch covering the month. */
    public function compute(Request $request, Project $project)
    {
        $data = $request->validate(['month' => array_merge(['required'], self::MONTH, [new DateInRange()])], $this->monthMessage());
        $month = $this->first($data['month']);

        $result = $this->payroll->computeMonth($project, $month->year, $month->month, $request->user());
        $rows = $result['rows'];
        $batch = $result['batch'];

        return response()->json([
            'message' => 'Payroll computed for ' . $rows->count() . ' ' . ($rows->count() === 1 ? 'entry' : 'entries') . '.'
                . $this->notIncludedText($project, $batch->periodStart(), $batch->periodEnd(), $result['removed']),
            // Rows taken out of this batch: not effective in the period any more, or honorarium 0.00.
            'removed_members' => $result['removed'],
        ] + $this->run($project, $batch->periodStart(), $batch->periodEnd()));
    }

    /** PATCH /api/payroll/{payroll} { basic_rate?, lib_category_id? } — no allowance: honoraria have none. */
    public function update(Request $request, Payroll $payroll)
    {
        $data = $request->validate([
            'basic_rate' => array_merge(['sometimes', 'gt:0'], MoneyInput::RULES),
            'allowance' => ['prohibited'],
            'lib_category_id' => ['sometimes', 'integer', $this->psLineItem($payroll->project)],
        ], [
            'lib_category_id.exists' => 'Choose a Personal Services (PS) line item of this project.',
            'allowance.prohibited' => 'Project honoraria have no allowance.',
        ]);

        return response()->json(['message' => 'Payroll updated.', 'payroll' => $this->row($this->payroll->update($payroll, $data, $request->user()))]);
    }

    // Batch hand-offs: the whole period at once.

    public function submit(Request $request, PayrollBatch $payrollBatch)
    {
        $batch = $this->payroll->submit($payrollBatch, $request->user());
        $message = $batch->pendingEscalation()
            ? 'Payroll submitted. It goes to the Program Leader for approval.'
            : 'Payroll submitted to the Project Leader.';

        return $this->batchResponse($message, $batch);
    }

    public function endorse(Request $request, PayrollBatch $payrollBatch)
    {
        return $this->batchResponse('Payroll approved by the Project Leader.', $this->payroll->endorse($payrollBatch, $request->user()));
    }

    public function returnBatch(Request $request, PayrollBatch $payrollBatch)
    {
        // Role and maker-checker first, then the reason.
        $reason = (string) $request->input('reason', '');

        return $this->batchResponse('Payroll returned.', $this->payroll->returnToPreparer($payrollBatch, $reason, $request->user()));
    }

    public function verify(Request $request, PayrollBatch $payrollBatch)
    {
        return $this->batchResponse('Payroll verified.', $this->payroll->verify($payrollBatch, $request->user()));
    }

    public function reject(Request $request, PayrollBatch $payrollBatch)
    {
        $reason = (string) $request->input('reason', '');

        return $this->batchResponse('Payroll rejected and sent back.', $this->payroll->reject($payrollBatch, $reason, $request->user()));
    }

    // ---------------------------------------------------------------- views

    /**
     * The batch covering $from (its own period), or the period $from..$to
     * as it would be prepared.
     */
    private function run(Project $project, Carbon $from, Carbon $to): array
    {
        $batch = $this->payroll->batchFor($project, $from->year, $from->month);
        if ($batch) {
            [$from, $to] = [$batch->periodStart(), $batch->periodEnd()];
        }

        $rows = $batch
            ? $batch->rows()->with(['personnel', 'libCategory', 'computedBy', 'plReviewedBy', 'verifiedBy'])->orderBy('id')->get()
            : collect();
        $months = PayrollBatch::monthsBetween($from, $to);

        return [
            'month' => $from->format('Y-m'),
            'to' => $to->format('Y-m'),
            'months' => $months,
            'month_label' => PayrollBatch::periodLabel($from, $to),
            'max_months' => PayrollService::MAX_MONTHS,
            'batch' => $batch ? $this->batch($batch) : null,
            'rows' => $rows->map(fn (Payroll $row) => $this->row($row))->values(),
            'totals' => [
                'gross' => round((float) $rows->sum('gross_pay'), 2),
                'net' => round((float) $rows->sum('net_pay'), 2),
                // Only rows recorded before deductions were removed (verified batches stay as recorded).
                'recorded_deductions' => round((float) $rows->sum('total_deductions'), 2),
            ],
            // Rows recorded with an allowance or deductions (older batches).
            'legacy_amounts' => $rows->contains(fn (Payroll $row) => $row->hasLegacyAmounts()),
            // Without a batch: why this period cannot be prepared (it overlaps another batch).
            'overlap_text' => $batch ? null : $this->payroll->overlapMessage($project, $from, $to),
            'rated_members' => $this->payroll->payees($project, $from, $to)->count(),
            // Personnel not paid in the period: named, never computed as 0.00.
            'not_included' => $this->payroll->notIncluded($project, $from, $to),
            'ps_line_items' => $project->libCategories()->where('category_type', 'PS')->orderBy('id')->get()
                ->map(fn (LibCategory $item) => ['id' => $item->id, 'name' => $item->line_item_name])->values(),
        ];
    }

    private function batchResponse(string $message, PayrollBatch $batch)
    {
        return response()->json(['message' => $message] + $this->run($batch->project, $batch->periodStart(), $batch->periodEnd()));
    }

    private function batch(PayrollBatch $batch): array
    {
        $batch->loadMissing(['preparedBy', 'submittedBy', 'plReviewedBy', 'verifiedBy']);
        $person = fn ($user) => $user ? ['id' => $user->id, 'name' => $user->name] : null;

        return [
            'id' => $batch->id,
            'reference' => $batch->reference(),
            'period_label' => $batch->monthLabel(),
            'months' => $batch->monthCount(),
            'workflow_status' => $batch->workflow_status,
            'workflow_label' => PayrollService::LABELS[$batch->workflow_status] ?? $batch->workflow_status,
            'editable' => $batch->isEditable(),
            // A draft / returned batch still carrying old allowances or deductions.
            'needs_recompute' => $this->payroll->needsRecompute($batch),
            // Line items this draft / returned batch would overspend (submit is refused while any).
            'over_balance' => $batch->isEditable() ? $this->payroll->overBalance($batch, false) : [],
            // Self-pay escalation to the Program Leader: 'approve' / 'verify' step, or null.
            'escalated_step' => ($batch->pendingEscalation()?->payload ?? [])['step'] ?? null,
            'waiting_text' => $this->payroll->waitingText($batch, request()->user()),
            'verification_blocked' => $this->payroll->isVerificationBlocked($batch),
            'can_verify' => request()->user() ? $this->payroll->canVerify($batch, request()->user()) : false,
            'return_reason' => $batch->return_reason,
            'documents_count' => $batch->documents()->count(),
            'prepared_by' => $person($batch->preparedBy),
            'submitted_by' => $person($batch->submittedBy),
            'pl_reviewed_by' => $person($batch->plReviewedBy),
            'verified_by' => $person($batch->verifiedBy),
            'submitted_at' => $batch->submitted_at?->toIso8601String(),
            'pl_reviewed_at' => $batch->pl_reviewed_at?->toIso8601String(),
            'verified_at' => $batch->verified_at?->toIso8601String(),
        ];
    }

    private function row(Payroll $payroll): array
    {
        $payroll->loadMissing(['personnel', 'libCategory', 'computedBy', 'plReviewedBy', 'verifiedBy']);
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
            // Monthly honorarium and the months paid: "(8,800 x 3 months) = 26,400.00".
            'basic' => round((float) $payroll->basic_rate, 2),
            'months' => $payroll->monthsPaid(),
            'segments' => $payroll->segments(),
            'computation' => $payroll->computationText(),
            'gross' => round((float) $payroll->gross_pay, 2),
            'net' => round((float) $payroll->net_pay, 2),
            // Non-zero only on rows recorded before deductions were removed.
            'recorded_deductions' => round((float) $payroll->total_deductions, 2),
            'status' => $payroll->status,
            'workflow_status' => $payroll->workflow_status,
            'workflow_label' => PayrollService::LABELS[$payroll->workflow_status] ?? $payroll->workflow_status,
            'return_reason' => $payroll->return_reason,
            'payroll_batch_id' => $payroll->payroll_batch_id,
            'computed_by' => $person($payroll->computedBy),
            'pl_reviewed_by' => $person($payroll->plReviewedBy),
            'verified_by' => $person($payroll->verifiedBy),
            'submitted_at' => $payroll->submitted_at?->toIso8601String(),
            'verified_at' => $payroll->verified_at?->toIso8601String(),
        ];
    }

    /**
     * " Not included: appointment not effective: A, B. Not included
     * (honorarium 0.00): C." (empty when none). $removed adds people whose
     * row Recompute just took out.
     */
    private function notIncludedText(Project $project, Carbon $from, Carbon $to, array $removed = []): string
    {
        $people = collect($this->payroll->notIncluded($project, $from, $to));
        $notEffective = $people->where('reason', 'not_effective')->pluck('name');
        $zero = $people->where('reason', 'zero')->pluck('name');
        $removed = collect($removed)->diff($notEffective)->diff($zero);

        $text = '';
        foreach ([
            'Not included: ' . PayrollService::NOT_EFFECTIVE => $notEffective,
            'Not included (honorarium 0.00)' => $zero,
            'Removed from this payroll' => $removed,
        ] as $label => $names) {
            if ($names->isNotEmpty()) {
                $text .= ' ' . $label . ': ' . $names->unique()->sort()->values()->join(', ', ' and ') . '.';
            }
        }

        return $text;
    }

    private function psLineItem(Project $project)
    {
        return Rule::exists((new LibCategory())->getTable(), 'id')
            ->where('project_id', $project->id)
            ->where('category_type', 'PS');
    }

    /** "2025-10" -> 1 October 2025. */
    private function first(string $month): Carbon
    {
        return Carbon::create((int) substr($month, 0, 4), (int) substr($month, 5, 2), 1)->startOfDay();
    }

    private function monthMessage(): array
    {
        return ['month.regex' => 'Use a month in the form YYYY-MM.', 'to.regex' => 'Use a month in the form YYYY-MM.'];
    }
}

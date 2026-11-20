<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Project;
use App\Services\FinanceService;
use App\Services\ProjectSummaryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Finance/Admin pages and the read-only budget summary. Verify / reject use
 * the existing expense and payroll-batch routes. The only write here is
 * "request receipt" (Notify RA), which changes no record.
 */
class FinanceController extends Controller
{
    public function __construct(private FinanceService $finance, private ProjectSummaryService $summary)
    {
    }

    /** GET /api/projects/{project}/finance-overview */
    public function overview(Request $request, Project $project)
    {
        $this->finance->requireFinance($project, $request->user());

        return response()->json($this->finance->overview($project, $request->user()));
    }

    /** GET /api/projects/{project}/finance-badges -> { verification } (= To verify) */
    public function badges(Request $request, Project $project)
    {
        $this->finance->requireFinance($project, $request->user());

        return response()->json($this->finance->badges($project, $request->user()));
    }

    /** GET /api/projects/{project}/verification-queue?tab=waiting|verified|rejected&type=expense|payroll */
    public function queue(Request $request, Project $project)
    {
        $this->finance->requireFinance($project, $request->user());
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(FinanceService::TABS)],
            'type' => ['nullable', Rule::in(FinanceService::TYPES)],
        ]);

        return response()->json($this->finance->queue($project, $request->user(), $data['tab'] ?? 'waiting', $data['type'] ?? null));
    }

    /** GET /api/projects/{project}/verification-queue/{type}/{id} — one record, any stage. */
    public function show(Request $request, Project $project, string $type, int $id)
    {
        $this->finance->requireFinance($project, $request->user());
        abort_unless(in_array($type, FinanceService::TYPES, true), 404);

        return response()->json(['item' => $this->finance->show($project, $request->user(), $type, $id)]);
    }

    /** GET /api/projects/{project}/payroll-batches — every payroll period's batch with its next step. */
    public function payrollBatches(Request $request, Project $project)
    {
        $this->finance->requireFinance($project, $request->user());

        return response()->json([
            'batches' => $this->finance->payrollBatches($project, $request->user()),
        ]);
    }

    /** POST /api/expenses/{expense}/request-receipt — Notify RA about a missing receipt. */
    public function requestReceipt(Request $request, Expense $expense)
    {
        $count = $this->finance->requestReceipt($expense, $request->user());

        return response()->json(['message' => $count > 0
            ? 'Notified ' . $count . ' ' . ($count === 1 ? 'person' : 'people') . ' about the missing receipt.'
            : 'There is no one to notify: no active Research Assistant or recorder on this project.', 'notified' => $count]);
    }

    /** GET /api/projects/{project}/budget-summary — read-only, any member of the project. */
    public function summary(Project $project)
    {
        return response()->json($this->summary->summary($project));
    }
}

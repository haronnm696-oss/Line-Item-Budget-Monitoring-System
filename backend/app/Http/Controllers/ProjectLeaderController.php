<?php

namespace App\Http\Controllers;

use App\Rules\DateInRange;
use App\Models\LibCategory;
use App\Models\Project;
use App\Services\ProjectLeaderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only data for the Project Leader pages. Approve / return use the
 * existing workflow endpoints (expenses, payroll batches, purchase
 * requests); budget requests use /projects/{project}/budget-requests.
 */
class ProjectLeaderController extends Controller
{
    public function __construct(private ProjectLeaderService $leader)
    {
    }

    /** GET /api/projects/{project}/leader-overview */
    public function overview(Request $request, Project $project)
    {
        $this->leader->requireLeader($project, $request->user());

        return response()->json($this->leader->overview($project, $request->user()));
    }

    /** GET /api/projects/{project}/leader-badges -> { submissions, requests } */
    public function badges(Request $request, Project $project)
    {
        $this->leader->requireLeader($project, $request->user());

        return response()->json($this->leader->badges($project, $request->user()));
    }

    /** POST /api/projects/{project}/budget-requests/seen — My requests was opened. */
    public function requestsSeen(Request $request, Project $project)
    {
        $this->leader->requireLeader($project, $request->user());
        $this->leader->markDecisionsSeen($project, $request->user());

        return response()->json($this->leader->badges($project, $request->user()));
    }

    /** GET /api/projects/{project}/review-queue?tab=waiting|returned|approved&type=expense|payroll|purchase_request */
    public function queue(Request $request, Project $project)
    {
        $this->leader->requireLeader($project, $request->user());
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(ProjectLeaderService::TABS)],
            'type' => ['nullable', Rule::in(ProjectLeaderService::TYPES)],
        ]);

        return response()->json($this->leader->queue($project, $request->user(), $data['tab'] ?? 'waiting', $data['type'] ?? null));
    }

    /** GET /api/projects/{project}/review-queue/{type}/{id} — one record, any stage. */
    public function show(Request $request, Project $project, string $type, int $id)
    {
        $this->leader->requireLeader($project, $request->user());
        abort_unless(in_array($type, ProjectLeaderService::TYPES, true), 404);

        return response()->json(['item' => $this->leader->show($project, $request->user(), $type, $id)]);
    }

    /** GET /api/projects/{project}/transactions?from=&to=&line_item=&category=PS|MOOE&receipt=missing&page= (project members) */
    public function transactions(Request $request, Project $project)
    {
        $data = $request->validate([
            'from' => ['bail', 'nullable', new DateInRange(), 'date'],
            'to' => ['bail', 'nullable', new DateInRange(), 'date', 'after_or_equal:from'],
            'line_item' => ['nullable', 'integer', Rule::exists((new LibCategory())->getTable(), 'id')->where('project_id', $project->id)],
            'category' => ['nullable', Rule::in(['PS', 'MOOE'])],
            // Verified expenses without a receipt (ProjectFinancialService::missingReceipt); no payroll.
            'receipt' => ['nullable', Rule::in(['missing'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'line_item.exists' => 'Choose a line item of this project.',
        ]);

        return response()->json($this->leader->transactions($project, $data));
    }
}

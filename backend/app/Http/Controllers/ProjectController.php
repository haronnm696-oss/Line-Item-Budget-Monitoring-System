<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Project;
use App\Services\DashboardService;

class ProjectController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    /**
     * Project list: every project for the Program Leader, otherwise only the
     * projects the user is assigned to. Spent comes from
     * ProjectFinancialService: verified expenses only (payroll is excluded),
     * the same figure as the Program Leader dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        // The caller's role on each project (null for the Program Leader's unassigned ones).
        $roles = $user->projectUsers()->with('role')->get()
            ->mapWithKeys(fn ($pivot) => [(int) $pivot->project_id => $pivot->role?->slug]);

        return response()->json(array_map(
            fn (array $row) => $row + ['my_role' => $roles[(int) $row['id']] ?? null],
            $this->dashboard->projectSummaries($user->accessibleProjectIds())
        ));
    }

    /**
     * Single-project dashboard data (line items, spend, tranches, approvals).
     */
    public function show(Project $project)
    {
        $payload = $this->dashboard->build($project);

        // Keep the response convenient for the existing project page while
        // retaining the canonical DashboardService shape.
        $payload['ps'] = $payload['categories']['ps'];
        $payload['mooe'] = $payload['categories']['mooe'];
        $payload['project']['agency'] = $payload['project']['funding_agency'];
        $payload['project']['budget'] = $payload['project']['total_budget'];
        $payload['project']['spent'] = $payload['project']['total_spent'];

        return response()->json($payload);
    }
}

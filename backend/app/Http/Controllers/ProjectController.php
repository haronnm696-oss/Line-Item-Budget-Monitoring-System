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
        return response()->json($this->dashboard->projectSummaries($request->user()->accessibleProjectIds()));
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

<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DashboardService;

class ProjectController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    /**
     * Project list. Spent is the same verified-expense + payroll rollup used
     * by the Program Leader dashboard, so both pages share one source.
     */
    public function index()
    {
        return response()->json($this->dashboard->projectSummaries());
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

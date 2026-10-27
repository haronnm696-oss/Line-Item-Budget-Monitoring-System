<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    /** Aggregate payload for the Program Leader dashboard. */
    public function programLeader(Request $request)
    {
        $project = $this->dashboard->resolveProject($request);

        return response()->json($this->dashboard->build($project));
    }

    /** Milestone/fund-release timeline. */
    public function tranches(Project $project)
    {
        return response()->json($this->dashboard->trancheRows($project));
    }

    /** Burn-rate and forecast indicator. */
    public function burnRate(Project $project)
    {
        return response()->json($this->dashboard->burnRate($project));
    }

    /** Receipt/document compliance tracker. */
    public function compliance(Project $project)
    {
        return response()->json($this->dashboard->compliance($project));
    }
}

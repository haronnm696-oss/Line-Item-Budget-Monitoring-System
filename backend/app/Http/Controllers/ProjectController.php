<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\User;
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
        // Every role the caller holds on each project (the "my projects"
        // resolver, see User::rolesOn). Pages of one role keep the projects
        // whose my_roles include that role. my_role is the one to display.
        $roles = $user->projectUsers()->with('role')->orderBy('id')->get()
            ->groupBy(fn ($pivot) => (int) $pivot->project_id)
            ->map(fn ($rows) => $rows->map(fn ($pivot) => $pivot->role?->slug)->filter()->unique()->values()->all());
        $accountRole = $user->primaryRole?->slug;

        return response()->json(array_map(
            fn (array $row) => $row + [
                'my_roles' => $roles[(int) $row['id']] ?? [],
                'my_role' => User::displayRole($roles[(int) $row['id']] ?? [], $accountRole),
            ],
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
        // PAYROLL_RATES_LABEL: printed at the top of the Payroll register.
        $payload['payroll_rates_label'] = config('payroll.rates_label');

        return response()->json($payload);
    }
}

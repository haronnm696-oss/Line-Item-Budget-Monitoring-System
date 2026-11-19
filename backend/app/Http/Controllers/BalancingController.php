<?php

namespace App\Http\Controllers;

use App\Models\LibCategory;
use App\Models\Project;
use App\Services\ProjectFinancialService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Balancing query per line item (ProjectFinancialService::balancing). */
class BalancingController extends Controller
{
    /** GET /api/projects/{project}/balancing?line_item= */
    public function show(Request $request, Project $project, ProjectFinancialService $financials)
    {
        $data = $request->validate([
            'line_item' => ['nullable', 'integer', Rule::exists((new LibCategory())->getTable(), 'id')->where('project_id', $project->id)],
        ], ['line_item.exists' => 'Choose a line item of this project.']);

        return response()->json($financials->balancing($project, isset($data['line_item']) ? (int) $data['line_item'] : null) + [
            // Labels of the two funding sources: the project's funder short name and the implementing agency.
            'labels' => ProjectFinancialService::sourceLabels($project->loadMissing('fundingAgency')),
        ]);
    }
}

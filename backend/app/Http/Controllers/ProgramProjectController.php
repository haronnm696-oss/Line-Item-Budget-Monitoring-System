<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProgramDashboardService;
use Illuminate\Http\Request;

class ProgramProjectController extends Controller
{
    public function __construct(private ProgramDashboardService $dashboard)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'funder' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->dashboard->projectList($data['funder'] ?? null));
    }

    public function show(Project $project)
    {
        return response()->json($this->dashboard->projectDetail($project));
    }
}

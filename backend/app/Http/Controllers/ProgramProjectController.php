<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProgramDashboardService;
use App\Services\ProjectListSpreadsheet;
use Illuminate\Http\Request;

class ProgramProjectController extends Controller
{
    public function __construct(private ProgramDashboardService $dashboard)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->dashboard->projectList($this->filters($request)));
    }

    /**
     * Excel export of exactly the list the page shows for the same filters.
     */
    public function export(Request $request, ProjectListSpreadsheet $spreadsheet)
    {
        $filters = $this->filters($request);
        $list = $this->dashboard->projectList($filters);

        $label = $list['summary']['funder_name'] ?? 'All funders';
        $filename = 'program-projects-' . now()->format('Ymd') . '.xls';

        return response($spreadsheet->render($list, $label), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    public function show(Project $project)
    {
        return response()->json($this->dashboard->projectDetail($project));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'funder' => ['nullable', 'string', 'max:255'],
            'leader' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:1900,2999'],
            'risk' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:title,spent,period'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $data['risk'] = filter_var($data['risk'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $data;
    }
}

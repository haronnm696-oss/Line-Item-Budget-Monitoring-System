<?php

namespace App\Http\Controllers;

use App\Support\Percent;
use App\Models\Project;
use App\Services\ProgramDashboardService;
use App\Services\ProgramUserService;
use App\Services\ProjectSetupService;
use App\Support\Csv;
use Illuminate\Http\Request;

class ProgramProjectController extends Controller
{
    /** Shown for a missing value in the export (same as the page). */
    private const MISSING = '—';

    public function __construct(private ProgramDashboardService $dashboard)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->dashboard->projectList($this->filters($request)));
    }

    /**
     * CSV export of exactly the list the page shows for the same filters
     * (same columns as the page's PDF), then the summary for those filters.
     * Money is a plain number with two decimals and percentages are numbers
     * (42.8), so the file sorts and sums in a spreadsheet.
     */
    public function export(Request $request)
    {
        $list = $this->dashboard->projectList($this->filters($request));
        $label = $list['summary']['funder_name'] ?? 'All funders';
        $filename = 'program-projects-' . now()->format('Ymd') . '.csv';

        return response(Csv::render($this->exportRows($list, $label)), 200, Csv::headers($filename));
    }

    public function show(Project $project, ProjectSetupService $setup, ProgramUserService $users)
    {
        // "setup": what the Program Leader may still change (ProjectSetupService rules).
        // "assignable_users": the people the Team's "Add member" dialog lists
        // (the same list as Users and roles > Assign to project).
        // "assign_project": this project for that dialog, with its current Project Leader.
        return response()->json($this->dashboard->projectDetail($project) + [
            'setup' => $setup->state($project),
            'assignable_users' => $users->assignableUsers(),
            'assign_project' => $users->assignableProjects($project)[0] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $list ProgramDashboardService::projectList() payload
     * @return array<int, array<int, mixed>>
     */
    private function exportRows(array $list, string $filterLabel): array
    {
        $text = function ($value, string $fallback = self::MISSING) {
            $value = is_string($value) ? trim($value) : $value;

            return ($value === null || $value === '') ? $fallback : (string) $value;
        };
        $money = fn ($value) => $value === null ? self::MISSING : number_format((float) $value, 2, '.', '');
        $pct = fn ($value) => $value === null ? self::MISSING : Percent::csv($value);

        $rows = [
            ['Program projects'],
            ['Generated ' . now()->format('Y-m-d H:i') . ' · ' . $filterLabel],
            [],
            [
                'Project', 'Code', 'Funder reference', 'Funder', 'Project leader',
                'Approved budget', 'Spent', 'Spent %', 'PS spent %', 'MOOE spent %', 'Remaining',
                'Start', 'End', 'Last entry', 'Status', 'Health', 'Health note', 'Pending approvals',
            ],
        ];

        foreach ($list['projects'] as $project) {
            $closed = ($project['health'] ?? null) === 'Closed';
            $rows[] = [
                $text($project['title'] ?? null),
                $text($project['code'] ?? null),
                $text($project['funder_reference'] ?? null),
                $text($project['funder'] ?? null),
                $text($project['project_leader'] ?? null),
                $money($project['budget'] ?? null),
                $money($project['spent'] ?? null),
                $pct($project['spent_pct'] ?? null),
                $pct($project['ps']['utilization_pct'] ?? null),
                $pct($project['mooe']['utilization_pct'] ?? null),
                $money($project['remaining'] ?? null),
                $text($project['start_date'] ?? null),
                $text($project['end_date'] ?? null),
                $text($project['last_entry_date'] ?? null, 'none yet'),
                $text($project['status'] ?? null),
                $text($closed ? null : ($project['health'] ?? null)),
                $text($project['health_note'] ?? null),
                (int) ($project['pending_approvals'] ?? 0),
            ];
        }

        $summary = $list['summary'];
        $rows[] = [];
        $rows[] = ['Summary'];
        $rows[] = ['Projects', (int) $summary['projects'], $summary['ongoing'] . ' ongoing · ' . $summary['completed'] . ' completed'];
        $rows[] = ['Total allocated', $money($summary['allocated'])];
        $rows[] = ['Total spent', $money($summary['spent']), $pct($summary['spent_pct']), '% of allocated'];
        $rows[] = ['Remaining', $money($summary['remaining']), $pct($summary['remaining_pct']), '% still available'];

        return $rows;
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

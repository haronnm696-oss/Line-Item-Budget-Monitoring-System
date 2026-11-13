<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\FundingAgency;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoDatasetSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramProjectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00'));

        $this->seed(DemoUsersSeeder::class);
        $this->seed(DemoDatasetSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_projects_list_matches_the_overview_for_every_funder_filter(): void
    {
        $leader = $this->programLeader();
        $filters = array_merge([null], FundingAgency::pluck('id')->map(fn ($id) => (string) $id)->all());

        foreach ($filters as $funder) {
            $query = $funder ? '?funder=' . $funder : '';

            $overview = $this->actingAs($leader)->getJson('/api/program/overview' . $query)->assertOk();
            $list = $this->actingAs($leader)->getJson('/api/program/projects' . $query)->assertOk();

            $overviewRows = collect($overview->json('projects'))->keyBy('id');
            $listRows = collect($list->json('projects'))->keyBy('id');

            $label = 'funder=' . ($funder ?? 'all');
            $this->assertSame($overviewRows->count(), $listRows->count(), $label);
            $this->assertSame($overviewRows->keys()->sort()->values()->all(), $listRows->keys()->sort()->values()->all(), $label);
            $this->assertEquals($overview->json('kpis.portfolio_budget'), $list->json('totals.budget'), $label);
            $this->assertEquals(round($overviewRows->sum('budget'), 2), round($listRows->sum('budget'), 2), $label);

            foreach ($overviewRows as $id => $row) {
                $this->assertEquals($row['spent_pct'], $listRows[$id]['spent_pct'], "$label project $id spent_pct");
                $this->assertSame($row['health'], $listRows[$id]['health'], "$label project $id health");
                $this->assertSame($row['health_reason'], $listRows[$id]['health_reason'], "$label project $id reason");
            }
        }
    }

    public function test_spent_percent_is_verified_expenses_over_the_approved_budget(): void
    {
        $rows = collect($this->actingAs($this->programLeader())
            ->getJson('/api/program/projects')
            ->assertOk()
            ->json('projects'))->keyBy('id');

        foreach (Project::all() as $project) {
            $spent = (float) $project->expenses()->where('status', 'verified')->sum('amount');
            $budget = (float) $project->total_approved_budget;

            $this->assertEquals(round(($spent / $budget) * 100, 1), $rows[$project->id]['spent_pct'], $project->code);
        }
    }

    public function test_seeded_projects_have_a_funder_reference_and_project_leader(): void
    {
        $rows = collect($this->actingAs($this->programLeader())
            ->getJson('/api/program/projects')
            ->assertOk()
            ->json('projects'))->keyBy('code');

        $this->assertSame('MOST-BARMM', $rows['MOST-RD-2026-001']['funder']);
        $this->assertSame('Dr. Aminah Macarambon', $rows['MOST-RD-2026-001']['project_leader']);
        $this->assertSame('DOST-PCAARRD', $rows['MOST-RD-2026-002']['funder']);
        $this->assertSame('CHED', $rows['MOST-RD-2026-003']['funder']);
        $this->assertSame('MOST-BARMM', $rows['MOST-RD-2025-001']['funder']);

        foreach ($rows as $code => $row) {
            $this->assertNotEmpty($row['funder_reference'], "$code reference");
            $this->assertNotEmpty($row['project_leader'], "$code leader");
        }
    }

    public function test_project_detail_uses_the_same_numbers_as_the_list(): void
    {
        $leader = $this->programLeader();
        $project = Project::where('code', 'MOST-RD-2026-001')->firstOrFail();

        $listRow = collect($this->actingAs($leader)->getJson('/api/program/projects')->json('projects'))
            ->firstWhere('id', $project->id);

        $detail = $this->actingAs($leader)
            ->getJson('/api/program/projects/' . $project->id)
            ->assertOk()
            ->assertJsonStructure([
                'project' => ['id', 'title', 'code', 'funder', 'funder_reference', 'project_leader', 'budget', 'spent', 'spent_pct', 'remaining', 'time_pct', 'health', 'health_reason'],
                'line_items' => ['groups', 'total'],
                'tranches',
                'expenses',
                'team',
                'activity',
            ]);

        foreach (['budget', 'spent', 'remaining', 'spent_pct', 'time_pct', 'health', 'health_reason'] as $field) {
            $this->assertEquals($listRow[$field], $detail->json('project.' . $field), $field);
        }

        $groups = collect($detail->json('line_items.groups'));
        $this->assertSame(['PS', 'MOOE'], $groups->pluck('type')->all());
        $this->assertEquals(
            round($groups->sum('subtotal.allocated'), 2),
            $detail->json('line_items.total.allocated')
        );
        $this->assertEquals(
            round((float) $project->expenses()->where('status', 'verified')->sum('amount'), 2),
            $detail->json('line_items.total.spent')
        );

        $this->assertLessThanOrEqual(10, count($detail->json('expenses')));
        $this->assertLessThanOrEqual(10, count($detail->json('activity')));
        $this->assertNotEmpty($detail->json('activity'));
        $this->assertContains('Dr. Aminah Macarambon', collect($detail->json('team'))->pluck('name')->all());
    }

    public function test_project_endpoints_are_forbidden_for_other_roles(): void
    {
        $project = Project::firstOrFail();

        foreach (['project.leader@gmail.com', 'finance@gmail.com', 'assistant@gmail.com', 'researcher@gmail.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->actingAs($user)->getJson('/api/program/projects')->assertForbidden();
            $this->actingAs($user)->getJson('/api/program/projects/' . $project->id)->assertForbidden();
        }
    }

    public function test_project_endpoints_require_authentication(): void
    {
        $this->getJson('/api/program/projects')->assertUnauthorized();
        $this->getJson('/api/program/projects/export')->assertUnauthorized();
        $this->getJson('/api/program/projects/' . Project::firstOrFail()->id)->assertUnauthorized();
    }

    public function test_unfiltered_summary_equals_the_overview_totals_exactly(): void
    {
        $leader = $this->programLeader();
        $overview = $this->actingAs($leader)->getJson('/api/program/overview')->assertOk();
        $summary = $this->actingAs($leader)->getJson('/api/program/projects')->assertOk()->json('summary');

        $this->assertSame($overview->json('meta.project_count'), $summary['projects']);
        $this->assertEquals($overview->json('kpis.portfolio_budget'), $summary['allocated']);
        $this->assertEquals($overview->json('kpis.total_disbursed'), $summary['spent']);
        $this->assertEquals($overview->json('kpis.utilization_pct'), $summary['spent_pct']);
        $this->assertEquals(
            round($overview->json('kpis.portfolio_budget') - $overview->json('kpis.total_disbursed'), 2),
            $summary['remaining']
        );
        $this->assertSame(
            $summary['projects'],
            $summary['ongoing'] + $summary['completed']
                + collect($overview->json('projects'))->whereNotIn('status', ['Ongoing', 'Completed'])->count()
        );
        $this->assertNull($summary['funder_name']);
    }

    public function test_funder_filter_summary_only_counts_that_funders_projects(): void
    {
        $leader = $this->programLeader();

        foreach (FundingAgency::all() as $agency) {
            $response = $this->actingAs($leader)
                ->getJson('/api/program/projects?funder=' . $agency->id)
                ->assertOk();
            $summary = $response->json('summary');
            $projects = Project::where('funding_agency_id', $agency->id)->get();

            $this->assertSame($projects->count(), $summary['projects'], $agency->name);
            $this->assertSame($agency->name, $summary['funder_name']);
            $this->assertEqualsCanonicalizing(
                $projects->pluck('id')->all(),
                collect($response->json('projects'))->pluck('id')->all(),
                $agency->name
            );

            $budget = $projects->sum(fn (Project $project) => app(\App\Services\ProjectFinancialService::class)->budget($project));
            $spent = $projects->sum(fn (Project $project) => app(\App\Services\ProjectFinancialService::class)->spent($project));
            $this->assertEquals(round($budget, 2), $summary['allocated'], $agency->name);
            $this->assertEquals(round($spent, 2), $summary['spent'], $agency->name);
        }
    }

    public function test_pending_approval_counts_per_project_are_correct(): void
    {
        $project = Project::where('code', 'MOST-RD-2026-001')->firstOrFail();
        $requester = User::where('email', 'project.leader@gmail.com')->firstOrFail();

        ApprovalRequest::create([
            'project_id' => $project->id,
            'type' => 'budget_realignment',
            'title' => 'Extra pending request for the count test',
            'justification' => 'Needed to prove the per-project pending count.',
            'amount' => 1000,
            'status' => 'pending',
            'requested_by' => $requester->id,
        ]);
        ApprovalRequest::create([
            'project_id' => $project->id,
            'type' => 'budget_realignment',
            'title' => 'Already approved, must not count',
            'justification' => 'Approved rows are not pending.',
            'amount' => 500,
            'status' => 'approved',
            'requested_by' => $requester->id,
        ]);

        $rows = collect($this->actingAs($this->programLeader())
            ->getJson('/api/program/projects')
            ->assertOk()
            ->json('projects'))->keyBy('id');

        foreach (Project::all() as $row) {
            $expected = ApprovalRequest::where('project_id', $row->id)->where('status', 'pending')->count();
            $this->assertSame($expected, $rows[$row->id]['pending_approvals'], $row->code);
        }

        $this->assertGreaterThanOrEqual(1, $rows[$project->id]['pending_approvals']);
    }

    public function test_leader_year_search_and_risk_filters_narrow_rows_and_summary(): void
    {
        $leader = $this->programLeader();
        $all = $this->actingAs($leader)->getJson('/api/program/projects')->assertOk();

        $leaderOption = collect($all->json('leaders'))->firstWhere('name', 'Dr. Aminah Macarambon');
        $this->assertNotNull($leaderOption);
        $byLeader = $this->actingAs($leader)->getJson('/api/program/projects?leader=' . $leaderOption['id'])->assertOk();
        $this->assertNotEmpty($byLeader->json('projects'));
        foreach ($byLeader->json('projects') as $row) {
            $this->assertSame('Dr. Aminah Macarambon', $row['project_leader']);
        }
        $this->assertSame(count($byLeader->json('projects')), $byLeader->json('summary.projects'));

        $year = 2025;
        $byYear = $this->actingAs($leader)->getJson('/api/program/projects?year=' . $year)->assertOk();
        $expected = collect($all->json('projects'))->filter(fn ($row) => $row['start_date'] <= "$year-12-31"
            && $row['end_date'] >= "$year-01-01")->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, collect($byYear->json('projects'))->pluck('id')->sort()->values()->all());

        $search = $this->actingAs($leader)->getJson('/api/program/projects?q=most-rd-2026-001')->assertOk();
        $this->assertSame(['MOST-RD-2026-001'], collect($search->json('projects'))->pluck('code')->all());

        $risk = $this->actingAs($leader)->getJson('/api/program/projects?risk=1')->assertOk();
        $this->assertSame($all->json('at_risk_count'), count($risk->json('projects')));
        foreach ($risk->json('projects') as $row) {
            $this->assertSame('At risk', $row['health']);
        }
        $this->assertEquals(round(collect($risk->json('projects'))->sum('budget'), 2), $risk->json('summary.allocated'));
    }

    public function test_sorting_is_applied_by_the_server(): void
    {
        $rows = $this->actingAs($this->programLeader())
            ->getJson('/api/program/projects?sort=spent&dir=desc')
            ->assertOk()
            ->json('projects');

        $pcts = array_column($rows, 'spent_pct');
        $sorted = $pcts;
        rsort($sorted);
        $this->assertSame($sorted, $pcts);
    }

    public function test_closed_projects_are_not_tracked_and_reasons_use_schedule_wording(): void
    {
        $rows = collect($this->actingAs($this->programLeader())
            ->getJson('/api/program/projects')
            ->assertOk()
            ->json('projects'));

        foreach ($rows as $row) {
            if ($row['health'] === 'Closed') {
                $this->assertSame('Not tracked after completion', $row['health_note'], $row['code']);
            }
            if (str_starts_with($row['health_reason'], 'Under-spending')) {
                $this->assertMatchesRegularExpression('/^Spending [\d.]+ pts behind schedule$/', $row['health_note'], $row['code']);
            }
            $this->assertArrayHasKey('utilization_pct', $row['ps']);
            $this->assertArrayHasKey('utilization_pct', $row['mooe']);
            $this->assertArrayHasKey('last_entry_date', $row);
        }
    }

    public function test_excel_export_lists_the_filtered_projects_with_totals(): void
    {
        $agency = FundingAgency::firstOrFail();

        $response = $this->actingAs($this->programLeader())
            ->get('/api/program/projects/export?funder=' . $agency->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');

        $body = $response->getContent();
        $this->assertStringContainsString('<Workbook', $body);
        $this->assertStringContainsString('Total allocated', $body);

        foreach (Project::all() as $project) {
            if ((int) $project->funding_agency_id === (int) $agency->id) {
                $this->assertStringContainsString('>' . $project->code . '<', $body);
            } else {
                $this->assertStringNotContainsString('>' . $project->code . '<', $body);
            }
        }
    }

    public function test_projects_export_is_forbidden_for_other_roles(): void
    {
        foreach (['project.leader@gmail.com', 'finance@gmail.com', 'assistant@gmail.com', 'researcher@gmail.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->actingAs($user)->getJson('/api/program/projects/export')->assertForbidden();
        }
    }

    private function programLeader(): User
    {
        return User::where('email', 'program.leader@gmail.com')->firstOrFail();
    }
}

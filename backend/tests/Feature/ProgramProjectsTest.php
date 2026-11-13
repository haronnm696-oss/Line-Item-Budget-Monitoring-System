<?php

namespace Tests\Feature;

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
        $this->getJson('/api/program/projects/' . Project::firstOrFail()->id)->assertUnauthorized();
    }

    private function programLeader(): User
    {
        return User::where('email', 'program.leader@gmail.com')->firstOrFail();
    }
}

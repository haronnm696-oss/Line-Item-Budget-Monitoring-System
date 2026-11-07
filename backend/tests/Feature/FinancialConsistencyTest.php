<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectFinancialService;
use Carbon\Carbon;
use Database\Seeders\DemoDatasetSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00'));

        $this->seed(DemoUsersSeeder::class);
        $this->seed(DemoDatasetSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Dashboard, Projects, Reports, and the Program Leader dashboard must all
     * report the exact same budget, spent, remaining, and utilization figures
     * for every seeded project.
     */
    public function test_all_financial_surfaces_agree_for_every_seeded_project(): void
    {
        $leader = User::where('email', 'program.leader@gmail.com')->firstOrFail();
        $projects = Project::orderBy('code')->get();

        $this->assertGreaterThanOrEqual(4, $projects->count());

        $list = collect($this->actingAs($leader)
            ->getJson('/api/projects')
            ->assertOk()
            ->json())->keyBy('code');

        $overview = collect($this->actingAs($leader)
            ->getJson('/api/program/overview')
            ->assertOk()
            ->json('projects'))->keyBy('code');

        foreach ($projects as $project) {
            $expectedSpent = (float) $project->expenses()
                ->where('status', 'verified')
                ->sum('amount');
            $expectedBudget = (float) $project->total_approved_budget;
            $expectedRemaining = round($expectedBudget - $expectedSpent, 2);
            $expectedUtilization = $expectedBudget > 0
                ? round(($expectedSpent / $expectedBudget) * 100, 1)
                : 0.0;

            $listRow = $list->get($project->code);
            $this->assertNotNull($listRow, "Projects page is missing {$project->code}.");

            $detail = $this->actingAs($leader)
                ->getJson("/api/projects/{$project->id}")
                ->assertOk();

            $dashboard = $this->actingAs($leader)
                ->getJson("/api/dashboard/program-leader?project_id={$project->id}")
                ->assertOk();

            $overviewRow = $overview->get($project->code);
            $this->assertNotNull($overviewRow, "Program dashboard is missing {$project->code}.");

            $this->assertMoney($expectedBudget, $listRow['budget'], "{$project->code} list budget");
            $this->assertMoney($expectedSpent, $listRow['spent'], "{$project->code} list spent");
            $this->assertMoney($expectedRemaining, $listRow['remaining'], "{$project->code} list remaining");

            $this->assertMoney($expectedBudget, $detail->json('project.total_budget'), "{$project->code} detail budget");
            $this->assertMoney($expectedSpent, $detail->json('project.total_spent'), "{$project->code} detail spent");
            $this->assertMoney($expectedRemaining, $detail->json('project.remaining'), "{$project->code} detail remaining");

            $this->assertMoney($expectedBudget, $dashboard->json('kpis.total_budget'), "{$project->code} dashboard budget");
            $this->assertMoney($expectedSpent, $dashboard->json('kpis.total_spent'), "{$project->code} dashboard spent");
            $this->assertMoney($expectedRemaining, $dashboard->json('kpis.remaining'), "{$project->code} dashboard remaining");

            $this->assertMoney($expectedBudget, $overviewRow['budget'], "{$project->code} overview budget");
            $this->assertMoney($expectedSpent, $overviewRow['spent'], "{$project->code} overview spent");
            $this->assertMoney($expectedRemaining, $overviewRow['remaining'], "{$project->code} overview remaining");

            $this->assertEqualsWithDelta(
                $expectedUtilization,
                (float) $listRow['utilization_pct'],
                0.01,
                "{$project->code} list utilization"
            );
            $this->assertEqualsWithDelta(
                $expectedUtilization,
                (float) $detail->json('project.utilization_pct'),
                0.01,
                "{$project->code} detail utilization"
            );
            $this->assertEqualsWithDelta(
                $expectedUtilization,
                (float) $dashboard->json('kpis.utilization_pct'),
                0.01,
                "{$project->code} dashboard utilization"
            );
            $this->assertEqualsWithDelta(
                $expectedUtilization,
                (float) $overviewRow['spent_pct'],
                0.01,
                "{$project->code} overview utilization"
            );

            $psSum = (float) collect($detail->json('categories.ps'))->sum('spent');
            $mooeSum = (float) collect($detail->json('categories.mooe'))->sum('spent');
            $this->assertMoney($expectedSpent, $psSum + $mooeSum, "{$project->code} category roll-up");

            $csv = $this->actingAs($leader)
                ->get("/api/projects/{$project->id}/reports/utilization")
                ->assertOk()
                ->getContent();

            [$csvBudget, $csvSpent, $csvRemaining, $csvUtilization] = $this->grandTotal($csv);
            $this->assertMoney($expectedBudget, $csvBudget, "{$project->code} report budget");
            $this->assertMoney($expectedSpent, $csvSpent, "{$project->code} report spent");
            $this->assertMoney($expectedRemaining, $csvRemaining, "{$project->code} report remaining");
            $this->assertEqualsWithDelta(
                $expectedUtilization,
                (float) $csvUtilization,
                0.01,
                "{$project->code} report utilization"
            );
        }
    }

    public function test_receipt_compliance_is_null_when_a_project_has_no_expenses(): void
    {
        $project = Project::create([
            'code' => 'MOST-RD-2026-900',
            'title' => 'Zero expense project',
            'total_approved_budget' => 100000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);

        $financials = app(ProjectFinancialService::class)->financials($project);

        $this->assertSame(0, $financials['expenses_count']);
        $this->assertNull($financials['receipt_compliance_pct']);

        $leader = User::where('email', 'program.leader@gmail.com')->firstOrFail();
        $this->actingAs($leader)
            ->getJson("/api/projects/{$project->id}/compliance")
            ->assertOk()
            ->assertJsonPath('compliance_pct', null)
            ->assertJsonPath('status', 'No expenses yet');
    }

    private function grandTotal(string $csv): array
    {
        $content = ltrim($csv, "\xEF\xBB\xBF");

        foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
            if (str_starts_with(trim($line), 'GRAND TOTAL')) {
                $row = str_getcsv(trim($line));
                return [
                    (float) $row[2],
                    (float) $row[3],
                    (float) $row[4],
                    (float) $row[5],
                ];
            }
        }

        $this->fail('Utilization CSV did not contain a GRAND TOTAL row.');
    }

    private function assertMoney(float $expected, $actual, string $message): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.01, $message);
    }
}

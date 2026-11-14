<?php

namespace Tests\Feature;

use App\Console\Commands\AuditLineItems;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoDatasetSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Descriptive analytics on the Program Leader Overview: monthly expense
 * trend, PS vs MOOE, and released funds. Spent = verified expenses only.
 */
class ProgramOverviewAnalyticsTest extends TestCase
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

    /** @return array<int, string|null> */
    protected function funderFilters(): array
    {
        return array_merge([null], FundingAgency::pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    protected function overview(?string $funder)
    {
        return $this->actingAs($this->programLeader())
            ->getJson('/api/program/overview' . ($funder ? '?funder=' . $funder : ''))
            ->assertOk();
    }

    public function test_monthly_amounts_add_up_to_verified_spending_for_the_year(): void
    {
        foreach ($this->funderFilters() as $funder) {
            $response = $this->overview($funder);
            $months = collect($response->json('monthly_expenses'));
            $projectIds = collect($response->json('projects'))->pluck('id');

            $expected = (float) Expense::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', 'verified')
                ->whereBetween('expense_date', ['2026-01-01', '2026-11-30'])
                ->sum('amount');

            $label = 'funder=' . ($funder ?? 'all');
            $this->assertCount(12, $months, $label);
            $this->assertEqualsWithDelta($expected, $months->sum('amount'), 0.01, $label);
        }
    }

    public function test_future_months_have_no_amount(): void
    {
        $months = collect($this->overview(null)->json('monthly_expenses'));

        $this->assertSame(
            ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'],
            $months->pluck('month')->all()
        );

        foreach ($months as $month) {
            $future = $month['month'] > '2026-11';
            $this->assertSame($future, $month['is_future'], $month['month']);
            if ($future) {
                $this->assertNull($month['amount'], $month['month']);
            } else {
                $this->assertIsNumeric($month['amount'], $month['month']);
            }
        }
    }

    public function test_ps_and_mooe_add_up_to_line_items_and_total_spent(): void
    {
        foreach ($this->funderFilters() as $funder) {
            $response = $this->overview($funder);
            $summary = $response->json('category_summary');
            $projectIds = collect($response->json('projects'))->pluck('id');
            $label = 'funder=' . ($funder ?? 'all');

            $lineItems = (float) LibCategory::whereIn('project_id', $projectIds)->sum('allocated_amount');
            $this->assertEqualsWithDelta($lineItems, $summary['ps']['allocated'] + $summary['mooe']['allocated'], 0.01, $label);
            $this->assertEqualsWithDelta($lineItems, $summary['line_items_total'], 0.01, $label);

            // Uncategorized verified expenses are shown, never dropped.
            $this->assertEqualsWithDelta(
                (float) $response->json('kpis.total_spent'),
                $summary['ps']['spent'] + $summary['mooe']['spent'] + $summary['uncategorized']['spent'],
                0.01,
                $label
            );
            $this->assertEqualsWithDelta(
                $summary['ps']['allocated'] - $summary['ps']['spent'],
                $summary['ps']['remaining'],
                0.01,
                $label
            );
        }
    }

    public function test_seeded_expenses_all_sit_under_ps_or_mooe(): void
    {
        $this->assertSame([], AuditLineItems::uncategorizedExpenses());
        $this->assertSame(0.0, (float) $this->overview(null)->json('category_summary.uncategorized.spent'));
    }

    public function test_overview_total_spent_matches_the_projects_page_summary(): void
    {
        foreach ($this->funderFilters() as $funder) {
            $query = $funder ? '?funder=' . $funder : '';
            $overview = $this->overview($funder);
            $list = $this->actingAs($this->programLeader())->getJson('/api/program/projects' . $query)->assertOk();
            $label = 'funder=' . ($funder ?? 'all');

            $this->assertEquals($list->json('summary.spent'), $overview->json('kpis.total_spent'), $label);
            $this->assertEquals($overview->json('kpis.total_disbursed'), $overview->json('kpis.total_spent'), $label);
            $this->assertEquals($list->json('summary.remaining'), $overview->json('kpis.remaining'), $label);
        }
    }

    public function test_released_is_the_sum_of_released_tranches(): void
    {
        foreach ($this->funderFilters() as $funder) {
            $response = $this->overview($funder);
            $projectIds = collect($response->json('projects'))->pluck('id');
            $label = 'funder=' . ($funder ?? 'all');

            $released = (float) ProjectTranche::whereIn('project_id', $projectIds)->where('status', 'released')->sum('amount');
            $budget = (float) $response->json('kpis.portfolio_budget');

            $this->assertEqualsWithDelta($released, (float) $response->json('kpis.total_released'), 0.01, $label);
            $this->assertEqualsWithDelta(max($budget - $released, 0), (float) $response->json('kpis.not_yet_released'), 0.01, $label);
            $this->assertEqualsWithDelta(
                $released,
                collect($response->json('funding_sources'))->sum('released'),
                0.01,
                $label
            );
        }
    }

    public function test_line_item_audit_lists_projects_whose_line_items_differ_from_the_budget(): void
    {
        $before = collect(AuditLineItems::budgetMismatches())->pluck('code')->all();

        // Lists the seeded mismatches (if any) so they show up in the test log.
        $this->artisan('lib:audit-line-items')->assertExitCode(0);

        $project = Project::where('code', 'MOST-RD-2026-001')->firstOrFail();
        $project->update(['total_approved_budget' => (float) $project->total_approved_budget + 1000]);

        $after = collect(AuditLineItems::budgetMismatches())->keyBy('code');
        $this->assertArrayHasKey('MOST-RD-2026-001', $after->all());
        $this->assertCount(count(array_unique(array_merge($before, ['MOST-RD-2026-001']))), $after);

        $summary = $this->overview(null)->json('category_summary');
        $this->assertFalse($summary['matches_budget']);
        $this->assertNotEquals($summary['line_items_total'], $summary['approved_budget']);
    }

    protected function programLeader(): User
    {
        return User::where('email', 'program.leader@gmail.com')->firstOrFail();
    }
}

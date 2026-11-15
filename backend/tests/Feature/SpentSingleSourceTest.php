<?php

namespace Tests\Feature;

use App\Console\Commands\AuditLineItems;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectFinancialService;
use Carbon\Carbon;
use Database\Seeders\DemoDatasetSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every "spent" figure now goes through ProjectFinancialService::onlySpent().
 * The expected values below are computed with plain SQL exactly the way the
 * old per-file copies did ("before"), on the demo data, and compared with
 * what the code returns now ("after").
 */
class SpentSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00'));
        $this->seed(DemoUsersSeeder::class);
        $this->seed(DemoDatasetSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_demo_data_has_spending_to_compare(): void
    {
        $this->assertGreaterThan(0, DB::table('expenses')->where('status', 'verified')->count());
        $this->assertGreaterThan(0, DB::table('expenses')->where('status', '!=', 'verified')->count(), 'non-verified rows must exist so the rule is exercised');
    }

    public function test_project_and_service_totals_are_unchanged(): void
    {
        $service = app(ProjectFinancialService::class);

        foreach (Project::all() as $project) {
            // Before: ProjectFinancialService::spent — verified, any line item.
            $before = round((float) DB::table('expenses')->where('project_id', $project->id)->where('status', 'verified')->sum('amount'), 2);
            $this->assertSame($before, $service->spent($project), "service spent, {$project->code}");

            // Before: Project::totalSpent — verified with a line item, optionally by type.
            foreach ([null, 'PS', 'MOOE'] as $type) {
                $old = (float) DB::table('expenses')
                    ->join('lib_categories', 'lib_categories.id', '=', 'expenses.lib_category_id')
                    ->where('expenses.project_id', $project->id)
                    ->where('expenses.status', 'verified')
                    ->when($type, fn ($q) => $q->where('lib_categories.category_type', $type))
                    ->sum('expenses.amount');
                $this->assertEqualsWithDelta($old, $project->totalSpent($type), 0.001, "totalSpent({$type}), {$project->code}");
            }
        }
    }

    public function test_line_item_spent_is_unchanged(): void
    {
        foreach (LibCategory::all() as $category) {
            $before = (float) DB::table('expenses')->where('lib_category_id', $category->id)->where('status', 'verified')->sum('amount');
            $this->assertEqualsWithDelta($before, $category->spentAmount(), 0.001, "line item {$category->id}");
        }
    }

    public function test_overview_trend_and_ps_mooe_summary_are_unchanged(): void
    {
        $leader = User::where('email', 'program.leader@gmail.com')->firstOrFail();
        $overview = $this->actingAs($leader)->getJson('/api/program/overview')->assertOk()->json();

        // Before: verified expenses of this year, per month (future months have no amount).
        $year = now()->year;
        foreach ($overview['monthly_expenses'] as $index => $month) {
            if ($month['amount'] === null) {
                continue;
            }
            $monthNumber = $index + 1;
            $before = (float) DB::table('expenses')
                ->where('status', 'verified')
                ->whereBetween('expense_date', [
                    Carbon::create($year, $monthNumber, 1)->toDateString(),
                    Carbon::create($year, $monthNumber, 1)->endOfMonth()->toDateString(),
                ])
                ->sum('amount');
            $this->assertEqualsWithDelta($before, (float) $month['amount'], 0.01, "trend {$month['month']}");
        }

        // Before: verified expenses grouped by their line item's PS/MOOE type.
        foreach (['PS' => 'ps', 'MOOE' => 'mooe'] as $type => $key) {
            $before = round((float) DB::table('expenses')
                ->join('lib_categories', 'lib_categories.id', '=', 'expenses.lib_category_id')
                ->where('expenses.status', 'verified')
                ->where('lib_categories.category_type', $type)
                ->sum('expenses.amount'), 2);
            $this->assertEqualsWithDelta($before, (float) $overview['category_summary'][$key]['spent'], 0.001, "summary {$type}");
        }
    }

    public function test_recent_expenses_report_rows_and_audit_command_are_unchanged(): void
    {
        $leader = User::where('email', 'program.leader@gmail.com')->firstOrFail();

        foreach (Project::all() as $project) {
            // Before: the 10 newest verified expenses.
            $beforeIds = DB::table('expenses')
                ->where('project_id', $project->id)
                ->where('status', 'verified')
                ->orderByDesc('expense_date')
                ->orderByDesc('id')
                ->limit(10)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $after = $this->actingAs($leader)->getJson("/api/program/projects/{$project->id}")->assertOk()->json('expenses');
            $this->assertSame($beforeIds, array_map(fn ($row) => (int) $row['id'], $after), "recent expenses, {$project->code}");

            // Before: the "verified" liquidation report lists verified expenses and totals them.
            $csv = $this->actingAs($leader)->get("/api/projects/{$project->id}/reports/liquidation?compliance=verified")->assertOk()->getContent();
            $beforeTotal = (float) DB::table('expenses')->where('project_id', $project->id)->where('status', 'verified')->sum('amount');
            $totalLine = collect(preg_split('/\r?\n/', trim($csv)))->first(fn ($line) => str_starts_with($line, 'TOTAL'));
            $this->assertEqualsWithDelta($beforeTotal, (float) str_getcsv($totalLine)[4], 0.001, "liquidation report total, {$project->code}");
        }

        // Before: verified expenses whose line item is neither PS nor MOOE.
        $before = DB::table('expenses')
            ->leftJoin('lib_categories', 'lib_categories.id', '=', 'expenses.lib_category_id')
            ->where('expenses.status', 'verified')
            ->where(fn ($q) => $q->whereNull('lib_categories.category_type')->orWhereNotIn('lib_categories.category_type', ['PS', 'MOOE']))
            ->orderBy('expenses.id')
            ->pluck('expenses.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertSame($before, array_map(fn ($row) => (int) $row['id'], AuditLineItems::uncategorizedExpenses()));
    }
}

<?php

namespace Tests\Feature;

use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\PayrollDeduction;
use App\Models\Project;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Payroll register (Reports PDF / CSV, the Research Assistant's Payroll
 * page): verified batches only, per month and per person, totals equal to
 * the batches, and only for the user's projects.
 */
class PayrollRegisterTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private LibCategory $ps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Manila'));
        config(['payroll.rates_label' => null]);
        $this->ps = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS',
            'line_item_name' => 'Salaries of project staff', 'allocated_amount' => 900000,
        ]));

        // Two verified months, and one batch in every other stage.
        $this->batch($this->project, 2026, 9, 'verified', [[30000, 2000, ['GSIS' => 2700, 'PhilHealth' => 750, 'Pag-IBIG' => 100, 'Withholding Tax' => 1200]]]);
        $this->batch($this->project, 2026, 10, 'verified', [
            [30000, 2000, ['GSIS' => 2700, 'PhilHealth' => 750, 'Pag-IBIG' => 100, 'Withholding Tax' => 1200]],
            [25000, 0, ['GSIS' => 2250, 'PhilHealth' => 625, 'Pag-IBIG' => 100]],
        ]);
        foreach (['draft' => 11, 'submitted' => 12, 'endorsed' => 8, 'returned' => 7] as $status => $month) {
            $this->batch($this->project, 2026, $month, $status, [[99999, 0, ['GSIS' => 1]]]);
        }
        // Another project's verified payroll.
        $this->batch($this->otherProject, 2026, 10, 'verified', [[77777, 0, []]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<int, array{0: float, 1: float, 2: array<string, float>}> $people */
    private function batch(Project $project, int $year, int $month, string $status, array $people): PayrollBatch
    {
        return AuditLogger::muted(function () use ($project, $year, $month, $status, $people) {
            $batch = PayrollBatch::create([
                'project_id' => $project->id, 'pay_year' => $year, 'pay_month' => $month,
                'prepared_by' => $this->assistant->id, 'workflow_status' => $status,
                'verified_by' => $status === 'verified' ? $this->finance->id : null,
                'verified_at' => $status === 'verified' ? now() : null,
            ]);
            foreach ($people as $i => [$rate, $allowance, $deductions]) {
                $person = $i === 0 ? $this->researcher : $this->member('researcher');
                $gross = $rate + $allowance;
                $total = array_sum($deductions);
                $row = Payroll::create([
                    'project_id' => $project->id, 'payroll_batch_id' => $batch->id, 'lib_category_id' => $this->ps->id,
                    'personnel_id' => $person->id, 'computed_by' => $this->assistant->id,
                    'pay_year' => $year, 'pay_month' => $month, 'basic_rate' => $rate, 'allowance' => $allowance,
                    'gross_pay' => $gross, 'total_deductions' => $total, 'net_pay' => $gross - $total,
                    'status' => $status === 'verified' ? 'approved' : 'draft', 'workflow_status' => $status,
                ]);
                foreach ($deductions as $type => $amount) {
                    PayrollDeduction::create(['payroll_id' => $row->id, 'deduction_type' => $type, 'amount' => $amount]);
                }
            }

            return $batch;
        });
    }

    private function url(Project $project, string $query = ''): string
    {
        return "/api/projects/{$project->id}/payroll-register{$query}";
    }

    public function test_only_verified_batches_appear(): void
    {
        $register = $this->actingAs($this->projectLeader)->getJson($this->url($this->project))->assertOk();

        $this->assertSame(['2026-09', '2026-10'], array_column($register->json('months'), 'month'));
        $this->assertNotContains(99999.0, collect($register->json('months'))->flatMap(fn ($m) => array_column($m['rows'], 'monthly_rate'))->map(fn ($v) => (float) $v)->all());
        $this->assertSame(['GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax'], $register->json('deduction_types'));

        // A single month that is not verified gives an empty register, never the batch.
        $this->getJson($this->url($this->project, '?month=2026-11'))->assertOk()->assertJsonPath('months', []);
    }

    public function test_totals_equal_the_batches(): void
    {
        $register = $this->actingAs($this->finance)->getJson($this->url($this->project))->assertOk()->json();

        foreach ($register['months'] as $month) {
            $batch = PayrollBatch::with('rows.deductions')->findOrFail($month['batch_id']);
            $this->assertSame('verified', $batch->workflow_status);
            $this->assertCount($batch->rows->count(), $month['rows']);
            $this->assertEqualsWithDelta((float) $batch->rows->sum('gross_pay'), $month['totals']['gross'], 0.001);
            $this->assertEqualsWithDelta((float) $batch->rows->sum('total_deductions'), $month['totals']['total_deductions'], 0.001);
            $this->assertEqualsWithDelta((float) $batch->rows->sum('net_pay'), $month['totals']['net'], 0.001);
            $this->assertEqualsWithDelta((float) $batch->rows->sum('basic_rate'), $month['totals']['monthly_rate'], 0.001);
            $this->assertEqualsWithDelta((float) $batch->rows->sum('allowance'), $month['totals']['allowance'], 0.001);
            foreach ($register['deduction_types'] as $type) {
                $expected = (float) $batch->rows->flatMap->deductions->where('deduction_type', $type)->sum('amount');
                $this->assertEqualsWithDelta($expected, $month['totals']['deductions'][$type], 0.001, $type);
            }
            // Each row: the itemized deductions add up to its total.
            foreach ($month['rows'] as $row) {
                $this->assertEqualsWithDelta($row['total_deductions'], array_sum($row['deductions']), 0.001);
            }
        }

        // October: 32,000 + 25,000 gross; 4,750 + 2,975 deductions.
        $october = collect($register['months'])->firstWhere('month', '2026-10');
        $this->assertEquals(57000, $october['totals']['gross']);
        $this->assertEquals(7725, $october['totals']['total_deductions']);
        $this->assertEquals(49275, $october['totals']['net']);
        $this->assertEquals(1200, $october['totals']['deductions']['Withholding Tax']);

        // Grand total = the verified batches of the project.
        $verified = Payroll::whereIn('payroll_batch_id', PayrollBatch::where('project_id', $this->project->id)->where('workflow_status', 'verified')->pluck('id'));
        $this->assertEqualsWithDelta((float) $verified->sum('net_pay'), $register['totals']['net'], 0.001);
        $this->assertSame(3, $register['totals']['people']);
    }

    public function test_the_period_parameter_matches_the_reports_page(): void
    {
        $this->batch($this->project, 2025, 12, 'verified', [[10000, 0, []]]);
        $this->actingAs($this->projectLeader);

        $this->assertSame(['2025-12', '2026-09', '2026-10'], array_column($this->getJson($this->url($this->project, '?period=all'))->json('months'), 'month'));
        $this->assertSame(['2026-09', '2026-10'], array_column($this->getJson($this->url($this->project, '?period=year'))->json('months'), 'month'));
        // 18 Nov 2026 minus 90 days = 20 Aug 2026: August onwards.
        $this->assertSame(['2026-09', '2026-10'], array_column($this->getJson($this->url($this->project, '?period=90d'))->json('months'), 'month'));
        $this->assertSame(['2026-10'], array_column($this->getJson($this->url($this->project, '?month=2026-10'))->json('months'), 'month'));
        $this->getJson($this->url($this->project, '?period=forever'))->assertStatus(422);
    }

    public function test_the_csv_has_one_block_per_month_with_totals_and_the_label_only_when_set(): void
    {
        $csv = $this->actingAs($this->projectLeader)->get("/api/projects/{$this->project->id}/reports/payroll-register")->assertOk()->getContent();
        $rows = array_map('str_getcsv', explode("\r\n", substr($csv, 3)));
        $this->assertSame('Payroll register', $rows[0][0]);
        $this->assertStringNotContainsString('⚠', $csv);

        $header = array_search('Month', array_column($rows, 0), true);
        $this->assertSame(['Month', 'Name', 'Position', 'Monthly rate', 'Allowance', 'Gross pay', 'GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Total deductions', 'Net pay'], $rows[$header]);
        $monthTotals = array_values(array_filter($rows, fn ($row) => ($row[1] ?? '') === 'Month total'));
        $this->assertCount(2, $monthTotals);
        $this->assertSame('49275.00', $monthTotals[1][11]);
        $grand = collect($rows)->first(fn ($row) => $row[0] === 'GRAND TOTAL');
        $this->assertSame('76525.00', $grand[11]); // 27,250 + 49,275

        config(['payroll.rates_label' => 'Demo deduction rates, not official']);
        $csv = $this->get("/api/projects/{$this->project->id}/reports/payroll-register")->assertOk()->getContent();
        $this->assertSame('⚠ Demo deduction rates, not official', str_getcsv(explode("\r\n", substr($csv, 3))[0])[0]);
        $this->getJson($this->url($this->project))->assertJsonPath('rates_label', 'Demo deduction rates, not official');
    }

    public function test_each_role_sees_only_their_projects(): void
    {
        foreach ([$this->programLeader, $this->projectLeader, $this->finance, $this->researcher, $this->assistant] as $user) {
            $this->actingAs($user)->getJson($this->url($this->project))->assertOk();
            $this->get("/api/projects/{$this->project->id}/reports/payroll-register")->assertOk();
        }

        // Assigned to the other project only (Finance/Admin there).
        $this->actingAs($this->outsider)->getJson($this->url($this->project))->assertForbidden();
        $this->get("/api/projects/{$this->project->id}/reports/payroll-register")->assertForbidden();
        $other = $this->getJson($this->url($this->otherProject))->assertOk();
        $this->assertEquals(77777, $other->json('totals.monthly_rate'));

        // The Program Leader sees every project; the others not the other project.
        $this->actingAs($this->programLeader)->getJson($this->url($this->otherProject))->assertOk();
        foreach ([$this->projectLeader, $this->finance, $this->researcher] as $user) {
            $this->actingAs($user)->getJson($this->url($this->otherProject))->assertForbidden();
        }
    }

    public function test_the_research_assistant_downloads_their_projects_register_and_not_another(): void
    {
        $this->actingAs($this->assistant);

        $month = $this->getJson($this->url($this->project, '?month=2026-10'))->assertOk();
        $this->assertSame(['2026-10'], array_column($month->json('months'), 'month'));
        $csv = $this->get("/api/projects/{$this->project->id}/reports/payroll-register?month=2026-10")->assertOk();
        $this->assertStringContainsString('payroll-register-2026-10', $csv->headers->get('Content-Disposition'));

        $this->getJson($this->url($this->otherProject, '?month=2026-10'))->assertForbidden();
        $this->get("/api/projects/{$this->otherProject->id}/reports/payroll-register?month=2026-10")->assertForbidden();
    }
}

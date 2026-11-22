<?php

namespace Tests\Feature;

use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * One project, every money in every stage, and every screen that shows it:
 * each role's pages, Form B, the reports, the payroll register and the
 * balancing query must show the same allocated, spent, pending and
 * available (ProjectFinancialService is the single source).
 *
 *   Allocated = 1,000,000.00 (Supplies 500,000 MOOE + Honoraria 500,000 PS = approved budget)
 *   Spent     =    31,250.50 (verified expenses 1,250.50 + verified payroll 30,000)
 *   Pending   =     5,600.00 (expenses 200 submitted + 300 approved, approved open PR 100, payroll 5,000 submitted)
 *   Available =   963,149.50 (allocated - spent - pending)
 *
 * Drafts, returned items, deleted items, cancelled requests and draft
 * payroll count nowhere.
 */
class SameNumbersEverywhereTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private const ALLOCATED = 1000000.0;
    private const SPENT = 31250.5;
    private const SPENT_PAYROLL = 30000.0;
    private const PENDING = 5600.0;
    private const AVAILABLE = 963149.5;

    private LibCategory $ps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();

        AuditLogger::muted(function () {
            $this->ps = LibCategory::create([
                'project_id' => $this->project->id, 'category_type' => 'PS',
                'line_item_name' => 'Honoraria of project staff', 'allocated_amount' => 500000,
            ]);

            // Supplies (MOOE).
            $this->expenseIn('verified', null, true, ['amount' => 1000]);
            $this->expenseIn('verified', null, true, ['amount' => 250.50]);
            $this->expenseIn('submitted', null, true, ['amount' => 200]);
            $this->expenseIn('endorsed', null, true, ['amount' => 300]);
            $this->expenseIn('draft', null, true, ['amount' => 999]);
            $this->expenseIn('returned', null, true, ['amount' => 50]);
            $this->expenseIn('submitted', null, true, ['amount' => 777])->delete();

            foreach (['endorsed' => 100, 'submitted' => 400, 'draft' => 77, 'cancelled' => 2000] as $stage => $amount) {
                PurchaseRequest::create([
                    'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
                    'description' => "PR {$stage}", 'quantity' => 1, 'estimated_amount' => $amount, 'workflow_status' => $stage,
                ]);
            }

            // Honoraria (PS): one verified quarter, one submitted month, one draft.
            $this->batch(7, 'verified', 30000, 3);
            $this->batch(10, 'submitted', 5000);
            $this->batch(11, 'draft', 700);
        });
    }

    private function batch(int $month, string $status, float $gross, int $months = 1): void
    {
        $batch = PayrollBatch::create([
            'project_id' => $this->project->id, 'pay_year' => 2026, 'pay_month' => $month,
            'period_end_year' => $months > 1 ? 2026 : null, 'period_end_month' => $months > 1 ? $month + $months - 1 : null,
            'prepared_by' => $this->assistant->id, 'workflow_status' => $status,
            'verified_by' => $status === 'verified' ? $this->finance->id : null,
            'verified_at' => $status === 'verified' ? now() : null,
        ]);
        Payroll::create([
            'project_id' => $this->project->id, 'payroll_batch_id' => $batch->id, 'lib_category_id' => $this->ps->id,
            'personnel_id' => $this->researcher->id, 'computed_by' => $this->assistant->id,
            'pay_year' => 2026, 'pay_month' => $month, 'basic_rate' => $gross / $months, 'months' => $months,
            'computation' => [['honorarium' => $gross / $months, 'months' => $months]],
            'gross_pay' => $gross, 'net_pay' => $gross,
            'status' => $status === 'verified' ? 'approved' : 'draft', 'workflow_status' => $status,
        ]);
    }

    public function test_the_balancing_query_is_the_reference_for_every_role(): void
    {
        foreach ([$this->programLeader, $this->projectLeader, $this->assistant, $this->finance] as $user) {
            $totals = $this->as($user, "/api/projects/{$this->project->id}/balancing")->json('totals');

            $this->assertMoney(self::ALLOCATED, $totals['allocated'], 'balancing allocated');
            $this->assertMoney(self::SPENT, $totals['spent'], 'balancing spent');
            $this->assertMoney(self::SPENT_PAYROLL, $totals['spent_payroll'], 'balancing spent payroll');
            $this->assertMoney(self::PENDING, $totals['pending'], 'balancing pending');
            $this->assertMoney(self::AVAILABLE, $totals['available'], 'balancing available');
            $this->assertMoney($totals['allocated'] - $totals['spent'] - $totals['pending'], $totals['available'], 'available = allocated - spent - pending');
        }
    }

    public function test_program_leader_pages_show_the_same_numbers(): void
    {
        $p = $this->project->id;

        $row = collect($this->as($this->programLeader, '/api/program/overview')->json('projects'))->firstWhere('id', $p);
        $this->assertBudgetSpentRemaining($row['budget'], $row['spent'], $row['remaining'], 'overview row');

        $row = collect($this->as($this->programLeader, '/api/program/projects')->json('projects'))->firstWhere('id', $p);
        $this->assertBudgetSpentRemaining($row['budget'], $row['spent'], $row['remaining'], 'projects list row');

        $detail = $this->as($this->programLeader, "/api/program/projects/{$p}")->json();
        $this->assertBudgetSpentRemaining($detail['project']['budget'], $detail['project']['spent'], $detail['project']['remaining'], 'project detail');
        $this->assertMoney(self::ALLOCATED, $detail['line_items']['total']['allocated'], 'detail line items allocated');
        $this->assertMoney(self::SPENT, $detail['line_items']['total']['spent'], 'detail line items spent');
        $this->assertMoney(self::ALLOCATED, $detail['form_b']['grand_total']['total'], 'detail Form B total');
    }

    public function test_project_leader_pages_show_the_same_numbers(): void
    {
        $p = $this->project->id;

        $overview = $this->as($this->projectLeader, "/api/projects/{$p}/leader-overview")->json();
        $this->assertBudgetSpentRemaining($overview['kpis']['total_budget'], $overview['kpis']['total_spent'], $overview['kpis']['remaining'], 'leader overview');
        $this->assertMoney(self::SPENT, $overview['category_summary']['ps']['spent'] + $overview['category_summary']['mooe']['spent'], 'leader PS + MOOE');
        $this->assertMoney(self::ALLOCATED, $overview['category_summary']['line_items_total'], 'leader line items total');

        $this->assertProjectDetail($this->projectLeader, 'leader');
    }

    public function test_finance_and_researcher_pages_show_the_same_numbers(): void
    {
        $p = $this->project->id;

        $finance = $this->as($this->finance, "/api/projects/{$p}/finance-overview")->json('kpis');
        $this->assertMoney(self::ALLOCATED, $finance['total_budget'], 'finance budget');
        $this->assertMoney(self::SPENT, $finance['total_spent'], 'finance spent');

        // Finance budget page and the Researcher dashboard read the same summary.
        foreach ([$this->finance, $this->researcher] as $user) {
            $summary = $this->as($user, "/api/projects/{$p}/budget-summary")->json();
            $this->assertBudgetSpentRemaining($summary['kpis']['total_budget'], $summary['kpis']['total_spent'], $summary['kpis']['remaining'], 'budget summary');
            $this->assertMoney(self::ALLOCATED, collect($summary['line_items'])->sum('allocated'), 'budget summary line items allocated');
            $this->assertMoney(self::SPENT, collect($summary['line_items'])->sum('spent'), 'budget summary line items spent');
        }

        // Researcher budget page (read-only project detail).
        $this->assertProjectDetail($this->researcher, 'researcher');
    }

    public function test_form_b_reports_and_payroll_register_show_the_same_numbers(): void
    {
        $p = $this->project->id;

        $formB = $this->as($this->projectLeader, "/api/projects/{$p}/form-b")->json();
        $this->assertMoney(self::ALLOCATED, end($formB['footer']['values']), 'Form B grand total');

        // Utilization CSV: the line-item rows (Allocated, Spent columns).
        // Columns: Category, Type, Allocated, Spent, Remaining, Utilization %.
        $csv = $this->actingAs($this->projectLeader)->get("/api/projects/{$p}/reports/utilization")->assertOk()->getContent();
        $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim($csv)));
        $lines = array_filter($rows, fn ($r) => in_array($r[1] ?? null, ['PS', 'MOOE'], true));
        $this->assertCount(2, $lines);
        $this->assertMoney(self::ALLOCATED, array_sum(array_map(fn ($r) => (float) $r[2], $lines)), 'utilization allocated');
        $this->assertMoney(self::SPENT, array_sum(array_map(fn ($r) => (float) $r[3], $lines)), 'utilization spent');

        // Expense ledger (Reports page): its verified rows add up to spent.
        $ledger = collect($this->as($this->projectLeader, "/api/projects/{$p}")->json('expenses'));
        $this->assertMoney(self::SPENT, $ledger->where('status', 'Verified')->sum('amount'), 'ledger verified rows');

        // Payroll register: verified batches only, equal to the payroll half of spent.
        $register = $this->as($this->projectLeader, "/api/projects/{$p}/payroll-register")->json('totals');
        $this->assertMoney(self::SPENT_PAYROLL, $register['gross'], 'payroll register gross');
        $this->assertMoney(self::SPENT_PAYROLL, $register['net'], 'payroll register net');
    }

    private function assertProjectDetail(User $user, string $who): void
    {
        $detail = $this->as($user, "/api/projects/{$this->project->id}")->json();
        $this->assertBudgetSpentRemaining($detail['project']['total_budget'], $detail['project']['total_spent'], $detail['project']['remaining'], "{$who} project detail");
        $lines = collect($detail['categories']['ps'])->concat($detail['categories']['mooe']);
        $this->assertMoney(self::ALLOCATED, $lines->sum('allocated'), "{$who} line items allocated");
        $this->assertMoney(self::SPENT, $lines->sum('spent'), "{$who} line items spent");
    }

    /** Budget and spent as expected; remaining = budget - spent; minus pending it is the balancing "available". */
    private function assertBudgetSpentRemaining($budget, $spent, $remaining, string $where): void
    {
        $this->assertMoney(self::ALLOCATED, $budget, "{$where} budget");
        $this->assertMoney(self::SPENT, $spent, "{$where} spent");
        $this->assertMoney(self::ALLOCATED - self::SPENT, $remaining, "{$where} remaining");
        $this->assertMoney(self::AVAILABLE, (float) $remaining - self::PENDING, "{$where} remaining - pending = available");
    }

    private function as(User $user, string $url)
    {
        return $this->actingAs($user)->getJson($url)->assertOk();
    }

    private function assertMoney(float $expected, $actual, string $message): void
    {
        $this->assertSame(round($expected, 2), round((float) $actual, 2), $message);
    }
}

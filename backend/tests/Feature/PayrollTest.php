<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PayrollCalculator;
use App\Services\ProjectFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Payroll: pay rates on the assignment, "create for a month", compute with
 * the deduction rules, and the monthly batch workflow with maker-checker,
 * one audit entry and one notification per batch hand-off.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    /**
     * TEST VALUES ONLY, not official rates (the same block as the local
     * .env until the MSU accounting office provides the real ones). The
     * shipped config reads every value from the environment.
     */
    private const RULES = [
        ['type' => 'GSIS', 'method' => 'percent', 'base' => 'basic', 'rate' => 7.25, 'employee_share' => 1, 'pre_tax' => true],
        ['type' => 'PhilHealth', 'method' => 'percent', 'base' => 'basic', 'rate' => 3.4, 'employee_share' => 0.5, 'pre_tax' => true],
        ['type' => 'Pag-IBIG', 'method' => 'percent', 'base' => 'basic', 'rate' => 1.5, 'employee_share' => 1, 'pre_tax' => true],
        ['type' => 'Withholding Tax', 'method' => 'table', 'base' => 'taxable', 'brackets' => [
            ['over' => 0, 'base_tax' => 0, 'rate' => 0],
            ['over' => 30000, 'base_tax' => 0, 'rate' => 12.5],
        ], 'pre_tax' => false],
    ];

    private LibCategory $ps;
    private string $month = '2026-11';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        config(['payroll.deductions' => self::RULES, 'documents.disk' => 'local']);
        Storage::fake('local');
        $this->ps = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS',
            'line_item_name' => 'Salaries of project staff', 'allocated_amount' => 900000,
        ]));
    }

    // ------------------------------------------------------------ computation

    public function test_the_calculator_applies_each_rule_kind(): void
    {
        // basic 40,000 + allowance 2,000 = gross 42,000
        // GSIS 7.25% of 40,000 = 2,900; PhilHealth 3.4% x 0.5 of 40,000 = 680;
        // Pag-IBIG 1.5% of 40,000 = 600; taxable = 42,000 - 4,180 = 37,820
        // -> 12.5% of (37,820 - 30,000) = 977.50.
        $result = (new PayrollCalculator(self::RULES))->compute(40000, 2000);

        $this->assertSame(42000.0, $result['gross']);
        $this->assertSame([
            ['type' => 'GSIS', 'amount' => 2900.0],
            ['type' => 'PhilHealth', 'amount' => 680.0],
            ['type' => 'Pag-IBIG', 'amount' => 600.0],
            ['type' => 'Withholding Tax', 'amount' => 977.5],
        ], $result['deductions']);
        $this->assertSame(5157.5, $result['total_deductions']);
        $this->assertSame(36842.5, $result['net']);
        $this->assertSame([], $result['unset']);

        // Below the 30,000 bracket: no tax. 25,000 -> 1,812.50 + 425 + 375.
        $low = (new PayrollCalculator(self::RULES))->compute(25000);
        $this->assertSame([1812.5, 425.0, 375.0, 0.0], array_column($low['deductions'], 'amount'));
        $this->assertSame(22387.5, $low['net']);

        // Salary floor and a fixed rule with a cap.
        $floor = (new PayrollCalculator([
            ['type' => 'PhilHealth', 'method' => 'percent', 'base' => 'basic', 'rate' => 3.4, 'employee_share' => 0.5, 'base_min' => 10000],
            ['type' => 'Union dues', 'method' => 'fixed', 'amount' => 900, 'max' => 500],
        ]))->compute(8000);
        $this->assertSame([170.0, 500.0], array_column($floor['deductions'], 'amount')); // 3.4% x 0.5 of the 10,000 floor
    }

    public function test_unset_rules_deduct_nothing_and_are_reported(): void
    {
        // The shipped config, where every rate is still unset.
        // Every variable empty (the local .env may hold test values).
        config(['payroll.deductions' => $this->withEnv([], fn () => (require config_path('payroll.php'))['deductions'])]);

        $result = app(PayrollCalculator::class)->compute(25000, 1000);
        $this->assertSame(0.0, $result['total_deductions']);
        $this->assertSame(26000.0, $result['net']);
        $this->assertSame(['GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax'], $result['unset']);

        $this->actingAs($this->assistant)
            ->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")
            ->assertOk()
            ->assertJsonPath('unset_rules', ['GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax']);
    }

    public function test_the_config_reads_every_value_from_the_environment(): void
    {
        // TEST VALUES ONLY (not official rates); floors / ceilings test the bounds.
        $vars = [
            'PAYROLL_GSIS_RATE' => '7.25', 'PAYROLL_GSIS_EMPLOYEE_SHARE' => '1',
            'PAYROLL_PHILHEALTH_RATE' => '3.4', 'PAYROLL_PHILHEALTH_EMPLOYEE_SHARE' => '0.5',
            'PAYROLL_PHILHEALTH_BASE_MIN' => '10000', 'PAYROLL_PHILHEALTH_BASE_MAX' => '20000',
            'PAYROLL_PAGIBIG_RATE' => '1.5', 'PAYROLL_PAGIBIG_EMPLOYEE_SHARE' => '1',
            'PAYROLL_PAGIBIG_BASE_MIN' => '', 'PAYROLL_PAGIBIG_BASE_MAX' => '5000',
            'PAYROLL_WITHHOLDING_TAX_BRACKETS' => '[{"over":0,"base_tax":0,"rate":0},{"over":30000,"base_tax":0,"rate":12.5}]',
        ];
        $rules = $this->withEnv($vars, fn () => (require config_path('payroll.php'))['deductions']);

        $this->assertSame([7.25, 1.0, null, null], [$rules[0]['rate'], $rules[0]['employee_share'], $rules[0]['base_min'], $rules[0]['base_max']]);
        $this->assertSame([3.4, 0.5, 10000.0, 20000.0], [$rules[1]['rate'], $rules[1]['employee_share'], $rules[1]['base_min'], $rules[1]['base_max']]);
        $this->assertNull($rules[2]['base_min']); // empty = no floor
        $this->assertCount(2, $rules[3]['brackets']);

        // gross 42,000: GSIS 7.25% of 42,000 = 3,045; PhilHealth 3.4% x 0.5 of
        // min(42,000, 20,000) = 340; Pag-IBIG 1.5% of min(42,000, 5,000) = 75;
        // taxable 42,000 - 3,460 = 38,540 -> 12.5% of 8,540 = 1,067.50.
        $result = (new PayrollCalculator($rules))->compute(42000);
        $this->assertSame([3045.0, 340.0, 75.0, 1067.5], array_column($result['deductions'], 'amount'));
        $this->assertSame([], $result['unset']);
    }

    public function test_missing_or_invalid_environment_values_stay_unset(): void
    {
        $rules = $this->withEnv([
            'PAYROLL_GSIS_RATE' => '7.25',                   // no employee share: not assumed to be 100%
            'PAYROLL_PHILHEALTH_RATE' => 'five', 'PAYROLL_PHILHEALTH_EMPLOYEE_SHARE' => '0.5', // not a number
            'PAYROLL_PAGIBIG_RATE' => '1.5', 'PAYROLL_PAGIBIG_EMPLOYEE_SHARE' => '1.5',        // share above 1
            'PAYROLL_WITHHOLDING_TAX_BRACKETS' => '[{"over":0,"rate":12.5}]',                  // base_tax missing
        ], fn () => (require config_path('payroll.php'))['deductions']);

        $this->assertNull($rules[0]['employee_share']);
        $this->assertNull($rules[1]['rate']);
        $this->assertNull($rules[3]['brackets']);
        $result = (new PayrollCalculator($rules))->compute(30000);
        $this->assertSame(0.0, $result['total_deductions']);
        $this->assertSame(['GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax'], $result['unset']);
    }

    /** Run $read with these environment variables set, then restore them. */
    private function withEnv(array $vars, callable $read)
    {
        $all = array_fill_keys([
            'PAYROLL_GSIS_RATE', 'PAYROLL_GSIS_EMPLOYEE_SHARE', 'PAYROLL_GSIS_BASE_MIN', 'PAYROLL_GSIS_BASE_MAX',
            'PAYROLL_PHILHEALTH_RATE', 'PAYROLL_PHILHEALTH_EMPLOYEE_SHARE', 'PAYROLL_PHILHEALTH_BASE_MIN', 'PAYROLL_PHILHEALTH_BASE_MAX',
            'PAYROLL_PAGIBIG_RATE', 'PAYROLL_PAGIBIG_EMPLOYEE_SHARE', 'PAYROLL_PAGIBIG_BASE_MIN', 'PAYROLL_PAGIBIG_BASE_MAX',
            'PAYROLL_WITHHOLDING_TAX_BRACKETS',
        ], '');
        $set = function (array $values) {
            foreach ($values as $key => $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        };
        $set(array_merge($all, $vars));
        try {
            return $read();
        } finally {
            $set($all);
        }
    }

    // ------------------------------------------------------------ pay rates and create

    public function test_finance_sets_pay_rates_with_one_audit_entry_each(): void
    {
        $audit = AuditTrail::count();
        $this->actingAs($this->finance)
            ->patchJson("/api/projects/{$this->project->id}/pay-rates/{$this->researcher->id}", ['monthly_rate' => 30000, 'allowance' => 2000])
            ->assertOk()
            ->assertJsonPath('member.monthly_rate', 30000)
            ->assertJsonPath('member.allowance', 2000);
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame('pay_rate_changed', AuditTrail::latest('id')->value('action'));

        foreach ([$this->assistant, $this->projectLeader, $this->researcher, $this->outsider] as $user) {
            $this->actingAs($user)
                ->patchJson("/api/projects/{$this->project->id}/pay-rates/{$this->researcher->id}", ['monthly_rate' => 1])
                ->assertForbidden();
        }
        $this->actingAs($this->programLeader)
            ->patchJson("/api/projects/{$this->project->id}/pay-rates/{$this->researcher->id}", ['monthly_rate' => 31000])
            ->assertOk();
        $this->actingAs($this->finance)
            ->patchJson("/api/projects/{$this->project->id}/pay-rates/{$this->outsider->id}", ['monthly_rate' => 1])
            ->assertNotFound();
    }

    public function test_pay_rates_are_seen_by_finance_assistants_and_the_program_leader_only(): void
    {
        $this->rate($this->researcher, 30000, 1500);
        $url = "/api/projects/{$this->project->id}/pay-rates";

        foreach ([$this->finance, $this->assistant, $this->programLeader] as $user) {
            $members = collect($this->actingAs($user)->getJson($url)->assertOk()->json('members'))->keyBy('user_id');
            $this->assertSame(30000, $members[$this->researcher->id]['monthly_rate']);
            $this->assertSame(1500, $members[$this->researcher->id]['allowance']);
            $this->assertNull($members[$this->projectLeader->id]['monthly_rate']); // "Not set"
        }
        foreach ([$this->researcher, $this->projectLeader, $this->outsider] as $user) {
            $this->actingAs($user)->getJson($url)->assertForbidden();
        }

        // A Research Assistant reads but never edits.
        $this->actingAs($this->assistant)
            ->patchJson("{$url}/{$this->researcher->id}", ['monthly_rate' => 1])
            ->assertForbidden();
    }

    public function test_pay_rate_validation_and_one_audit_entry_per_change(): void
    {
        $url = "/api/projects/{$this->project->id}/pay-rates/{$this->researcher->id}";
        $this->actingAs($this->finance);
        $audit = AuditTrail::count();

        $this->patchJson($url, ['monthly_rate' => -1])->assertStatus(422)
            ->assertJsonPath('errors.monthly_rate.0', 'The monthly rate cannot be negative.');
        $this->patchJson($url, ['monthly_rate' => '1000.123'])->assertStatus(422)
            ->assertJsonPath('errors.monthly_rate.0', 'Use at most two decimals for the monthly rate.');
        $this->patchJson($url, ['monthly_rate' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['monthly_rate']);
        $this->patchJson($url, ['monthly_rate' => 1000, 'allowance' => -5])->assertStatus(422)
            ->assertJsonPath('errors.allowance.0', 'The allowance cannot be negative.');
        $this->patchJson($url, ['monthly_rate' => 1000, 'allowance' => '1.555'])->assertStatus(422)->assertJsonValidationErrors(['allowance']);
        $this->patchJson($url, ['allowance' => 1])->assertStatus(422)->assertJsonValidationErrors(['monthly_rate']); // must be present
        $this->assertSame($audit, AuditTrail::count());

        $this->patchJson($url, ['monthly_rate' => '25000.50', 'allowance' => '0'])->assertOk()
            ->assertJsonPath('member.monthly_rate', 25000.5)->assertJsonPath('member.allowance', 0);
        $this->assertSame($audit + 1, AuditTrail::count());

        // The same values again: nothing changed, no audit entry.
        $this->patchJson($url, ['monthly_rate' => 25000.5, 'allowance' => 0])->assertOk();
        $this->assertSame($audit + 1, AuditTrail::count());

        // Cleared = "Not set": both null, one more entry.
        $this->patchJson($url, ['monthly_rate' => null])->assertOk()
            ->assertJsonPath('member.monthly_rate', null)->assertJsonPath('member.allowance', null);
        $this->assertSame($audit + 2, AuditTrail::count());
        $this->assertSame(['pay_rate_changed', 'pay_rate_changed'], AuditTrail::latest('id')->take(2)->pluck('action')->all());
    }

    public function test_compute_names_the_people_without_a_rate_and_never_pays_them_zero(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->assistant, 0); // 0.00 counts as no rate
        $this->createMonth();

        $run = $this->actingAs($this->assistant)->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")->assertOk();
        $names = collect($run->json('unrated_members'))->pluck('name')->all();
        $this->assertContains($this->assistant->name, $names);
        $this->assertContains($this->projectLeader->name, $names);
        $this->assertContains($this->finance->name, $names);
        $this->assertNotContains($this->researcher->name, $names);
        $this->assertSame('zero', collect($run->json('unrated_members'))->firstWhere('user_id', $this->assistant->id)['reason']);

        $response = $this->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])->assertOk();
        $message = $response->json('message');
        $this->assertStringStartsWith('Payroll computed for 1 entry. Not included: no pay rate set for ', $message);
        foreach ([$this->assistant, $this->projectLeader, $this->finance] as $user) {
            $this->assertStringContainsString($user->name, $message);
        }
        $this->assertSame(1, Payroll::count());
        $this->assertSame(0, Payroll::where('gross_pay', 0)->count());
    }

    public function test_recompute_removes_rows_whose_rate_became_zero_or_not_set(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->assistant, 20000);
        $this->rate($this->projectLeader, 50000);
        $this->createMonth();
        $this->assertSame(3, Payroll::count());

        // The assistant's rate becomes 0.00 and the Project Leader's is cleared.
        $this->rate($this->assistant, 0);
        AuditLogger::muted(fn () => ProjectUser::where('project_id', $this->project->id)->where('user_id', $this->projectLeader->id)
            ->update(['monthly_rate' => null, 'allowance' => null]));
        $removedIds = Payroll::whereIn('personnel_id', [$this->assistant->id, $this->projectLeader->id])->pluck('id');

        $audit = AuditTrail::count();
        $response = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.personnel.id', $this->researcher->id);

        // Removed from the batch, not kept at their old amounts and never 0.00.
        $this->assertSame([$this->researcher->id], Payroll::pluck('personnel_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, \App\Models\PayrollDeduction::whereIn('payroll_id', $removedIds)->count());
        $message = $response->json('message');
        $this->assertStringStartsWith('Payroll computed for 1 entry. Not included: no pay rate set for ', $message);
        $this->assertStringContainsString($this->assistant->name, $message);
        $this->assertStringContainsString($this->projectLeader->name, $message);
        $this->assertEqualsCanonicalizing([$this->assistant->name, $this->projectLeader->name], $response->json('removed_members'));

        // One "deleted" entry per removed row, one "computed" for the row kept.
        $this->assertSame($audit + 3, AuditTrail::count());
        $this->assertSame(2, AuditTrail::where('id', '>', $audit)->where('action', 'deleted')->count());
        $this->assertSame(1, AuditTrail::where('id', '>', $audit)->where('action', 'computed')->count());

        // Once submitted, clearing a rate changes nothing and compute is refused.
        $this->payrollFileFor($this->batch(), $this->assistant);
        $this->postJson("/api/payroll-batches/{$this->batch()->id}/submit")->assertOk();
        $this->rate($this->researcher, 0);
        $this->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])
            ->assertStatus(422)
            ->assertJsonPath('errors.workflow_status.0', 'This payroll cannot be computed while it is submitted.');
        $this->assertSame(30000.0, (float) Payroll::where('personnel_id', $this->researcher->id)->value('gross_pay'));
    }

    public function test_create_for_a_month_computes_one_row_per_rated_active_member(): void
    {
        $this->rate($this->researcher, 40000, 2000);
        $this->rate($this->assistant, 20000, 0);
        $inactive = AuditLogger::muted(fn () => $this->member('researcher'));
        $this->rate($inactive, 25000);
        AuditLogger::muted(fn () => $inactive->forceFill(['is_active' => false])->save());
        // The project leader has no rate: no row.

        $audit = AuditTrail::count();
        $response = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertCreated()
            ->assertJsonPath('month_label', 'November 2026')
            ->assertJsonCount(2, 'rows');
        // People without a rate are named, not given a 0.00 row.
        $this->assertStringStartsWith('Payroll created for 2 people. Not included: no pay rate set for ', $response->json('message'));
        $this->assertStringContainsString($this->projectLeader->name, $response->json('message'));

        $this->assertSame($audit + 2, AuditTrail::count());
        $this->assertSame(['created', 'created'], AuditTrail::latest('id')->take(2)->pluck('action')->all());

        $row = collect($response->json('rows'))->firstWhere('personnel.id', $this->researcher->id);
        $this->assertSame(42000, $row['gross']);
        $this->assertSame(5157.5, $row['deductions']);
        $this->assertSame(36842.5, $row['net']);
        $this->assertSame('draft', $row['workflow_status']);
        $this->assertCount(4, $row['deduction_items']);
        $this->assertSame(4, Payroll::findOrFail($row['id'])->deductions()->count());

        $breakdown = collect($response->json('deductionBreakdown'))->pluck('amount', 'type');
        $this->assertSame(2900 + 1450, (int) $breakdown['GSIS']); // 7.25% of 40,000 and of 20,000

        // A second create for the same month adds nothing.
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.month.0', 'Payroll for November 2026 already exists for everyone with a pay rate.');
        $this->assertSame(2, Payroll::count());
    }

    public function test_create_is_refused_without_rates_or_for_the_wrong_role_or_line_item(): void
    {
        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.month.0', 'No one on this project has a monthly rate yet. Set pay rates first.');

        $this->rate($this->researcher, 30000);
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->lineItem->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lib_category_id']); // MOOE, not PS
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2026-13', 'lib_category_id' => $this->ps->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['month']);

        foreach ([$this->projectLeader, $this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)
                ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
                ->assertForbidden();
        }
        $this->actingAs($this->outsider)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertForbidden();

        $this->assertSame(0, Payroll::count());
    }

    public function test_compute_refreshes_every_row_while_the_batch_is_editable(): void
    {
        $this->rate($this->researcher, 30000, 2000);
        $this->rate($this->assistant, 20000);
        $this->createMonth();

        $this->rate($this->researcher, 40000, 0);
        $this->rate($this->assistant, 25000);

        $audit = AuditTrail::count();
        $response = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])
            ->assertOk()
            ->assertJsonPath('rated_members', 2);
        $this->assertStringStartsWith('Payroll computed for 2 entries. Not included: no pay rate set for ', $response->json('message'));
        $this->assertSame($audit + 2, AuditTrail::count()); // one per row changed
        $this->assertSame(40000.0, (float) Payroll::where('personnel_id', $this->researcher->id)->value('gross_pay'));
        $this->assertSame(25000.0, (float) Payroll::where('personnel_id', $this->assistant->id)->value('gross_pay'));

        // Not once the batch is submitted.
        $this->payrollFileFor($this->batch(), $this->assistant);
        $this->postJson("/api/payroll-batches/{$this->batch()->id}/submit")->assertOk();
        $this->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])
            ->assertStatus(422)
            ->assertJsonPath('errors.workflow_status.0', 'This payroll cannot be computed while it is submitted.');
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.workflow_status.0', 'This payroll cannot be changed while it is submitted.');
    }

    public function test_rows_are_edited_only_while_the_batch_is_draft_or_returned(): void
    {
        $payroll = $this->onePayroll();
        $audit = AuditTrail::count();

        $this->actingAs($this->assistant)
            ->patchJson("/api/payroll/{$payroll->id}", ['allowance' => 1000])
            ->assertOk()
            ->assertJsonPath('payroll.gross', 31000);
        $this->assertSame($audit + 1, AuditTrail::count());

        $batch = $this->batch();
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $this->patchJson("/api/payroll/{$payroll->id}", ['allowance' => 5])
            ->assertStatus(422)
            ->assertJsonPath('errors.workflow_status.0', 'This payroll cannot be edited while it is submitted.');

        // Returned: editable again.
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/return", ['reason' => 'Check the allowance.'])->assertOk();
        $this->actingAs($this->assistant)->patchJson("/api/payroll/{$payroll->id}", ['allowance' => 500])->assertOk();
    }

    // ------------------------------------------------------------ batch workflow

    public function test_the_whole_month_moves_with_one_audit_entry_and_one_notification_per_hand_off(): void
    {
        // No self-pay: neither the Project Leader nor a Finance/Admin is paid.
        $this->rate($this->researcher, 30000);
        $this->rate($this->assistant, 20000);
        $this->rate(AuditLogger::muted(fn () => $this->member('researcher')), 45000);
        $this->createMonth();
        $batch = $this->batch();
        $link = "/payroll?project={$this->project->id}&month={$this->month}";

        $audit = AuditTrail::count();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")
            ->assertOk()
            ->assertJsonPath('batch.workflow_status', 'submitted')
            ->assertJsonPath('batch.submitted_by.id', $this->assistant->id);
        $this->assertStep($audit, 'submitted', $batch->id);
        $this->assertNotified($this->projectLeader, 'submitted', $link);
        $this->assertRowsAre('submitted', 'draft');

        $audit = AuditTrail::count();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")
            ->assertOk()->assertJsonPath('batch.workflow_label', 'Approved by Project Leader');
        $this->assertStep($audit, 'endorsed', $batch->id);
        $this->assertNotified($this->finance, 'endorsed', $link);
        $this->assertNotified($this->finance2, 'endorsed', $link);
        $this->assertRowsAre('endorsed', 'draft');

        $audit = AuditTrail::count();
        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")
            ->assertOk()->assertJsonPath('batch.workflow_status', 'verified');
        $this->assertStep($audit, 'verified', $batch->id);
        $this->assertNotified($this->assistant, 'verified', $link);
        $this->assertRowsAre('verified', 'approved');

        // Three rows, three hand-offs: 1 + 2 + 1 notifications (one per recipient per hand-off).
        $this->assertSame(4, DB::table('notifications')->count());
        $this->assertSame(0.0, app(ProjectFinancialService::class)->spent($this->project));

        // Files of a verified payroll stay.
        $this->actingAs($this->assistant)->post("/api/payroll-batches/{$batch->id}/documents", [
            'file' => UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'), 'document_type' => 'payroll_file',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_return_and_reject_need_a_reason_and_go_back_to_the_submitter(): void
    {
        $this->onePayroll();
        $batch = $this->batch();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();

        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/return", ['reason' => ' '])->assertStatus(422);
        $audit = AuditTrail::count();
        $this->postJson("/api/payroll-batches/{$batch->id}/return", ['reason' => 'Wrong allowance.'])
            ->assertOk()->assertJsonPath('batch.workflow_status', 'returned')->assertJsonPath('batch.return_reason', 'Wrong allowance.');
        $this->assertStep($audit, 'returned', $batch->id);
        $this->assertNotified($this->assistant, 'returned');
        $this->assertRowsAre('returned', 'draft');

        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk()->assertJsonPath('batch.return_reason', null);
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();

        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $audit = AuditTrail::count();
        $this->postJson("/api/payroll-batches/{$batch->id}/reject", ['reason' => 'Missing DTR.'])
            ->assertOk()->assertJsonPath('batch.workflow_status', 'returned');
        $this->assertStep($audit, 'rejected', $batch->id);
        $this->assertSame('Missing DTR.', AuditTrail::latest('id')->value('reason'));
        $this->assertNotified($this->assistant, 'rejected');
    }

    public function test_batch_roles_and_maker_checker(): void
    {
        $this->onePayroll();
        $batch = $this->batch();

        // Only RA / FIN submit; only the Project Leader approves; only FIN verifies.
        foreach ([$this->projectLeader, $this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertForbidden();
        }
        $this->actingAs($this->outsider)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertForbidden();
        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertStatus(422); // draft: invalid transition

        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        foreach ([$this->assistant, $this->finance, $this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertForbidden();
        }
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();
        foreach ([$this->assistant, $this->projectLeader, $this->researcher] as $user) {
            $this->actingAs($user)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertForbidden();
        }
        $this->assertSame('endorsed', $batch->fresh()->workflow_status);
    }

    public function test_whoever_prepared_or_recomputed_the_batch_cannot_check_it(): void
    {
        // The Research Assistant prepares; Finance/Admin recomputes, so becomes a maker too.
        $this->onePayroll();
        $batch = $this->batch();
        $this->actingAs($this->finance)->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])->assertOk();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();

        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")
            ->assertForbidden()->assertJsonPath('message', 'You cannot verify or reject a payroll you prepared.');
        $this->postJson("/api/payroll-batches/{$batch->id}/reject", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->finance2)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk();
    }

    // ------------------------------------------------------------ self-pay escalation

    public function test_a_batch_with_the_project_leaders_pay_goes_to_the_program_leader(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->projectLeader, 45000);
        $this->createMonth();
        $batch = $this->batch();

        $audit = AuditTrail::count();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")
            ->assertOk()
            ->assertJsonPath('batch.workflow_status', 'submitted')
            ->assertJsonPath('batch.escalated_step', 'approve')
            ->assertJsonPath('batch.waiting_text', "Waiting for Program Leader (includes the Project Leader's pay)");
        $this->assertStep($audit, 'submitted', $batch->id);

        // A pending "payroll" approval request on the Program Leader Approvals page.
        $approval = ApprovalRequest::findOrFail($batch->fresh()->approval_request_id);
        $this->assertSame(['payroll', 'pending', $batch->id, 'approve'], [$approval->type, $approval->status, $approval->payload['payroll_batch_id'], $approval->payload['step']]);
        $this->assertSame(75000.0, (float) $approval->amount);
        $pending = collect($this->actingAs($this->programLeader)->getJson('/api/program/approvals?status=pending')->json('approvals'));
        $this->assertNotNull($pending->firstWhere('id', $approval->id));

        // One notification each: the Program Leader to decide, the Project Leader to know.
        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertSame("/program-approvals?project={$this->project->id}", $this->programLeader->notifications()->first()->data['link']);
        $note = $this->projectLeader->notifications()->first();
        $this->assertSame('escalated', $note->data['event']);
        $this->assertStringContainsString('Waiting for Program Leader (includes your pay)', $note->data['message']);

        // The Project Leader sees why and cannot approve or return it.
        $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")
            ->assertOk()->assertJsonPath('batch.waiting_text', 'Waiting for Program Leader (includes your pay)');
        $this->postJson("/api/payroll-batches/{$batch->id}/endorse")
            ->assertForbidden()->assertJsonPath('message', 'Waiting for Program Leader (includes your pay).');
        $this->postJson("/api/payroll-batches/{$batch->id}/return", ['reason' => 'x'])->assertForbidden();

        // The Program Leader approves: the batch moves with one audit entry (on the batch).
        $audit = AuditTrail::count();
        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved'])
            ->assertOk();
        $this->assertStep($audit, 'approved', $batch->id);
        $this->assertSame('endorsed', $batch->fresh()->workflow_status);
        $this->assertSame($this->programLeader->id, (int) $batch->fresh()->pl_reviewed_by);
        $this->assertRowsAre('endorsed', 'draft');
        $this->assertNotified($this->finance, 'endorsed');
        $this->assertNotified($this->finance2, 'endorsed');

        // Verification as usual.
        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk()->assertJsonPath('batch.workflow_status', 'verified');
    }

    public function test_a_program_leader_rejection_returns_the_batch_and_a_resubmit_escalates_again(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->projectLeader, 45000);
        $this->createMonth();
        $batch = $this->batch();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $first = (int) $batch->fresh()->approval_request_id;

        $audit = AuditTrail::count();
        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$first}/decision", ['decision' => 'rejected', 'remarks' => 'Honoraria rate is above the approved LIB.'])
            ->assertOk();
        $this->assertStep($audit, 'returned', $batch->id);
        $this->assertSame('returned', $batch->fresh()->workflow_status);
        $this->assertSame('Honoraria rate is above the approved LIB.', $batch->fresh()->return_reason);
        $this->assertNotified($this->assistant, 'returned');
        // No "budget request" notification for a payroll step.
        $this->assertCount(0, $this->assistant->notifications()->get()->filter(fn ($n) => $n->data['kind'] === 'budget_request'));

        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $second = (int) $batch->fresh()->approval_request_id;
        $this->assertNotSame($first, $second);
        $this->assertSame('pending', ApprovalRequest::find($second)->status);
    }

    public function test_a_batch_with_the_verifiers_pay_needs_another_verifier(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->finance, 28000);
        $this->createMonth();
        $batch = $this->batch();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")
            ->assertOk()->assertJsonPath('batch.escalated_step', null);

        // Only the Finance/Admin who may verify is told.
        $this->assertNotified($this->finance2, 'endorsed');
        $this->assertCount(0, $this->finance->notifications()->get());

        $this->actingAs($this->finance)
            ->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")
            ->assertJsonPath('batch.can_verify', false)
            ->assertJsonPath('batch.waiting_text', 'Waiting for another Finance/Admin (includes your pay)');
        $this->postJson("/api/payroll-batches/{$batch->id}/verify")
            ->assertForbidden()->assertJsonPath('message', 'You cannot verify or reject a payroll that includes your pay.');
        $this->postJson("/api/payroll-batches/{$batch->id}/reject", ['reason' => 'x'])->assertForbidden();

        $this->actingAs($this->finance2)
            ->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")
            ->assertJsonPath('batch.can_verify', true);
        $this->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk()->assertJsonPath('batch.workflow_status', 'verified');
    }

    public function test_with_no_other_verifier_the_program_leader_verifies(): void
    {
        $this->rate($this->finance, 28000);
        $this->rate($this->finance2, 27000);
        $this->createMonth();
        $batch = $this->batch();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();

        $audit = AuditTrail::count();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")
            ->assertOk()
            ->assertJsonPath('batch.escalated_step', 'verify')
            ->assertJsonPath('batch.waiting_text', 'Waiting for the Program Leader to verify (no other Finance/Admin can)');
        $this->assertStep($audit, 'endorsed', $batch->id);
        $approval = ApprovalRequest::findOrFail($batch->fresh()->approval_request_id);
        $this->assertSame(['payroll', 'verify', $this->projectLeader->id], [$approval->type, $approval->payload['step'], (int) $approval->requested_by]);
        $this->assertNotified($this->programLeader, 'endorsed');
        $this->assertCount(0, $this->finance->notifications()->get());

        foreach ([$this->finance, $this->finance2] as $user) {
            $this->actingAs($user)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertForbidden();
        }

        $audit = AuditTrail::count();
        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved'])
            ->assertOk();
        $this->assertStep($audit, 'verified', $batch->id);
        $this->assertSame($this->programLeader->id, (int) $batch->fresh()->verified_by);
        $this->assertRowsAre('verified', 'approved');
        $this->assertNotified($this->assistant, 'verified');
    }

    public function test_with_two_program_leaders_the_other_one_verifies(): void
    {
        // The Project Leader and every Finance/Admin are paid: both steps escalate.
        $secondLeader = AuditLogger::muted(fn () => $this->makePerson('program-leader'));
        $this->rate($this->projectLeader, 45000);
        $this->rate($this->finance, 28000);
        $this->rate($this->finance2, 27000);
        $this->createMonth();
        $batch = $this->batch();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();

        $this->actingAs($this->programLeader)
            ->postJson('/api/program/approvals/' . $batch->fresh()->approval_request_id . '/decision', ['decision' => 'approved'])
            ->assertOk();
        $verifyStep = ApprovalRequest::findOrFail($batch->fresh()->approval_request_id);
        $this->assertSame('verify', $verifyStep->payload['step']);

        $this->postJson("/api/program/approvals/{$verifyStep->id}/decision", ['decision' => 'approved'])
            ->assertForbidden()->assertJsonPath('message', 'You cannot approve or reject a request you created.');
        $this->assertSame('endorsed', $batch->fresh()->workflow_status);

        $this->actingAs($secondLeader)
            ->postJson("/api/program/approvals/{$verifyStep->id}/decision", ['decision' => 'approved'])
            ->assertOk();
        $this->assertSame('verified', $batch->fresh()->workflow_status);
    }

    public function test_a_single_program_leader_who_approved_cannot_verify_until_an_eligible_finance_admin_is_assigned(): void
    {
        // The only Program Leader approves (the Project Leader is paid); every
        // Finance/Admin is paid too, so nobody may verify.
        $this->rate($this->projectLeader, 45000);
        $this->rate($this->finance, 28000);
        $this->rate($this->finance2, 27000);
        $this->createMonth();
        $batch = $this->batch();
        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $approveStep = (int) $batch->fresh()->approval_request_id;

        $audit = AuditTrail::count();
        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$approveStep}/decision", ['decision' => 'approved'])
            ->assertOk();
        $this->assertStep($audit, 'approved', $batch->id);

        // Blocked: no verify request, the batch waits for verification.
        $this->assertSame('endorsed', $batch->fresh()->workflow_status);
        $this->assertSame($approveStep, (int) $batch->fresh()->approval_request_id);
        $this->assertSame(0, ApprovalRequest::where('status', 'pending')->where('type', 'payroll')->count());
        $message = 'No eligible verifier. Assign a Finance/Admin who is not paid in this batch to the project.';
        $this->actingAs($this->assistant)
            ->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")
            ->assertJsonPath('batch.verification_blocked', true)
            ->assertJsonPath('batch.waiting_text', 'Waiting for verification. ' . $message);

        // The Program Leader is told once (although they acted), with a link to Users and roles.
        $notes = $this->programLeader->notifications()->get()->filter(fn ($n) => $n->data['event'] === 'verification_blocked');
        $this->assertCount(1, $notes);
        $this->assertStringContainsString($message, $notes->first()->data['message']);
        $this->assertSame('/program-users', $notes->first()->data['link']);

        // Nobody can verify: not the paid Finance/Admin, not the approving Program Leader.
        foreach ([$this->finance, $this->finance2, $this->programLeader] as $user) {
            $this->actingAs($user)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertForbidden();
        }
        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$approveStep}/decision", ['decision' => 'approved'])
            ->assertStatus(409);

        // Assign an unpaid Finance/Admin: they verify normally.
        $newFinance = AuditLogger::muted(fn () => $this->member('finance-admin'));
        $this->actingAs($newFinance)
            ->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")
            ->assertJsonPath('batch.verification_blocked', false)
            ->assertJsonPath('batch.can_verify', true);
        $audit = AuditTrail::count();
        $this->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk()->assertJsonPath('batch.workflow_status', 'verified');
        $this->assertStep($audit, 'verified', $batch->id);
        $this->assertSame($newFinance->id, (int) $batch->fresh()->verified_by);
        $this->assertNotified($this->assistant, 'verified');
    }

    public function test_payroll_files_attach_to_the_batch(): void
    {
        $this->onePayroll();
        $batch = $this->batch();
        $batch->documents()->delete(); // start with no file
        $this->actingAs($this->assistant);
        $upload = fn (string $type) => $this->post("/api/payroll-batches/{$batch->id}/documents", [
            'file' => UploadedFile::fake()->create('payroll.pdf', 20, 'application/pdf'), 'document_type' => $type,
        ], ['Accept' => 'application/json']);

        $audit = AuditTrail::count();
        $upload('payroll_file')->assertCreated()->assertJsonPath('document.document_type', 'payroll_file');
        $this->assertSame($audit + 1, AuditTrail::count());
        $upload('receipt')->assertStatus(422)->assertJsonValidationErrors(['document_type']);

        $this->actingAs($this->projectLeader)->getJson("/api/payroll-batches/{$batch->id}/documents")->assertOk()->assertJsonCount(1, 'documents');
        $this->actingAs($this->outsider)->getJson("/api/payroll-batches/{$batch->id}/documents")->assertForbidden();
    }

    public function test_a_batch_without_a_payroll_file_cannot_be_submitted(): void
    {
        $this->onePayroll();
        $batch = $this->batch();
        $batch->documents()->delete();
        $audit = AuditTrail::count();

        // An "other" document is not the payroll file.
        $this->actingAs($this->assistant)->post("/api/payroll-batches/{$batch->id}/documents", [
            'file' => UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'), 'document_type' => 'other',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('errors.documents.0', 'Upload the payroll file before submitting.');
        $this->assertSame('draft', $batch->fresh()->workflow_status);
        $this->assertSame($audit + 1, AuditTrail::count()); // only the upload

        $this->post("/api/payroll-batches/{$batch->id}/documents", [
            'file' => $this->uniqueUpload('payroll.pdf'), 'document_type' => 'payroll_file',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk()->assertJsonPath('batch.workflow_status', 'submitted');
    }

    public function test_backfill_maps_the_old_payroll_status(): void
    {
        $base = [
            'project_id' => $this->project->id, 'lib_category_id' => $this->ps->id, 'computed_by' => $this->assistant->id,
            'pay_month' => 1, 'pay_year' => 2026, 'basic_rate' => 1, 'gross_pay' => 1, 'net_pay' => 1,
            'created_at' => '2026-01-31 08:00:00', 'updated_at' => '2026-02-02 08:00:00',
        ];
        $draft = DB::table('payrolls')->insertGetId($base + ['personnel_id' => $this->assistant->id, 'status' => 'draft']);
        $approved = DB::table('payrolls')->insertGetId($base + ['personnel_id' => $this->researcher->id, 'status' => 'approved']);
        $released = DB::table('payrolls')->insertGetId($base + ['personnel_id' => $this->finance->id, 'status' => 'released']);

        (require database_path('migrations/2026_11_17_000001_add_workflow_to_payrolls_table.php'))->backfill();

        $stage = fn ($id) => DB::table('payrolls')->where('id', $id)->value('workflow_status');
        $this->assertSame(['draft', 'verified', 'verified'], [$stage($draft), $stage($approved), $stage($released)]);
        $this->assertNotNull(DB::table('payrolls')->where('id', $released)->value('verified_at'));
    }

    public function test_existing_rows_are_grouped_into_monthly_batches(): void
    {
        $row = fn (int $month, int $personId, string $stage) => DB::table('payrolls')->insertGetId([
            'project_id' => $this->project->id, 'lib_category_id' => $this->ps->id, 'computed_by' => $this->assistant->id,
            'personnel_id' => $personId, 'pay_month' => $month, 'pay_year' => 2026, 'basic_rate' => 1, 'gross_pay' => 1,
            'net_pay' => 1, 'status' => $stage === 'verified' ? 'approved' : 'draft', 'workflow_status' => $stage,
            'created_at' => '2026-0' . $month . '-28 08:00:00', 'updated_at' => '2026-0' . $month . '-28 08:00:00',
        ]);
        // January: every row verified. February: mixed, so the batch is the least advanced (submitted).
        $jan = [$row(1, $this->assistant->id, 'verified'), $row(1, $this->researcher->id, 'verified')];
        $feb = [$row(2, $this->assistant->id, 'verified'), $row(2, $this->researcher->id, 'submitted')];

        $migration = require database_path('migrations/2026_11_18_000002_create_payroll_batches_table.php');
        $migration->backfill();
        $migration->backfill(); // safe to rerun

        $this->assertSame(2, PayrollBatch::count());
        $january = PayrollBatch::where('pay_month', 1)->firstOrFail();
        $february = PayrollBatch::where('pay_month', 2)->firstOrFail();
        $this->assertSame(['verified', 'submitted'], [$january->workflow_status, $february->workflow_status]);
        $this->assertSame($this->assistant->id, (int) $january->prepared_by);
        $this->assertSame($jan, Payroll::where('payroll_batch_id', $january->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame($feb, Payroll::where('payroll_batch_id', $february->id)->orderBy('id')->pluck('id')->all());
        // Rows are left as they were.
        $this->assertSame('verified', Payroll::find($feb[0])->workflow_status);
    }

    // ------------------------------------------------------------ helpers

    private function rate(User $user, float $monthly, float $allowance = 0): void
    {
        AuditLogger::muted(fn () => ProjectUser::where('project_id', $this->project->id)->where('user_id', $user->id)
            ->update(['monthly_rate' => $monthly, 'allowance' => $allowance]));
    }

    private function createMonth(?User $by = null): void
    {
        $this->actingAs($by ?? $this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertCreated();
        // The payroll file every submit needs (tests of the rule remove it).
        if (! $this->batch()->documents()->where('document_type', 'payroll_file')->exists()) {
            $this->payrollFileFor($this->batch(), $by ?? $this->assistant);
        }
    }

    /** One draft payroll row (researcher, 30,000) prepared by $by. */
    private function onePayroll(?User $by = null): Payroll
    {
        $this->rate($this->researcher, 30000);
        $this->createMonth($by);

        return Payroll::where('personnel_id', $this->researcher->id)->firstOrFail();
    }

    private function batch(): PayrollBatch
    {
        return PayrollBatch::where('project_id', $this->project->id)->where('pay_year', 2026)->where('pay_month', 11)->firstOrFail();
    }

    private function assertRowsAre(string $stage, string $status): void
    {
        $rows = Payroll::where('payroll_batch_id', $this->batch()->id)->get();
        $this->assertTrue($rows->isNotEmpty());
        $this->assertSame([$stage], $rows->pluck('workflow_status')->unique()->values()->all());
        $this->assertSame([$status], $rows->pluck('status')->unique()->values()->all());
    }

    private function assertStep(int $before, string $action, int $batchId): void
    {
        $this->assertSame($before + 1, AuditTrail::count(), "one audit entry for {$action}");
        $entry = AuditTrail::latest('id')->firstOrFail();
        $this->assertSame([$action, 'PayrollBatch', $batchId, $this->project->id], [$entry->action, $entry->model_type, (int) $entry->model_id, (int) $entry->project_id]);
    }

    private function assertNotified(User $user, string $event, ?string $link = null): void
    {
        $rows = $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === 'payroll' && ($n->data['event'] ?? null) === $event);
        $this->assertCount(1, $rows, "{$user->name}: one payroll {$event} notification");
        if ($link) {
            $this->assertSame($link, $rows->first()->data['link']);
        }
    }
}

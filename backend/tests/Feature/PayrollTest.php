<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\Payroll;
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
 * the deduction rules, and the expense-style workflow with maker-checker,
 * one audit entry per change and one notification per hand-off.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    /** Rules used by the tests (the shipped config leaves every rate unset). */
    private const RULES = [
        ['type' => 'GSIS', 'method' => 'percent', 'base' => 'basic', 'rate' => 9, 'pre_tax' => true],
        ['type' => 'PhilHealth', 'method' => 'percent', 'base' => 'basic', 'rate' => 5, 'employee_share' => 0.5, 'base_min' => 10000, 'base_max' => 100000, 'pre_tax' => true],
        ['type' => 'Pag-IBIG', 'method' => 'percent', 'base' => 'basic', 'rate' => 2, 'base_max' => 10000, 'pre_tax' => true],
        ['type' => 'Withholding Tax', 'method' => 'table', 'base' => 'taxable', 'brackets' => [
            ['over' => 0, 'base_tax' => 0, 'rate' => 0],
            ['over' => 20833, 'base_tax' => 0, 'rate' => 15],
            ['over' => 33333, 'base_tax' => 1875, 'rate' => 20],
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
        // basic 30,000 + allowance 2,000 = gross 32,000
        // GSIS 9% of 30,000 = 2,700; PhilHealth 5% x 0.5 of 30,000 = 750;
        // Pag-IBIG 2% of min(30,000, 10,000) = 200; taxable = 32,000 - 3,650
        // = 28,350 -> 15% of (28,350 - 20,833) = 1,127.55.
        $result = (new PayrollCalculator(self::RULES))->compute(30000, 2000);

        $this->assertSame(32000.0, $result['gross']);
        $this->assertSame([
            ['type' => 'GSIS', 'amount' => 2700.0],
            ['type' => 'PhilHealth', 'amount' => 750.0],
            ['type' => 'Pag-IBIG', 'amount' => 200.0],
            ['type' => 'Withholding Tax', 'amount' => 1127.55],
        ], $result['deductions']);
        $this->assertSame(4777.55, $result['total_deductions']);
        $this->assertSame(27222.45, $result['net']);
        $this->assertSame([], $result['unset']);

        // Salary floor and a fixed rule with a cap.
        $floor = (new PayrollCalculator([
            ['type' => 'PhilHealth', 'method' => 'percent', 'base' => 'basic', 'rate' => 5, 'employee_share' => 0.5, 'base_min' => 10000],
            ['type' => 'Union dues', 'method' => 'fixed', 'amount' => 900, 'max' => 500],
        ]))->compute(8000);
        $this->assertSame([250.0, 500.0], array_column($floor['deductions'], 'amount'));
    }

    public function test_unset_rules_deduct_nothing_and_are_reported(): void
    {
        // The shipped config, where every rate is still unset.
        config(['payroll.deductions' => (require config_path('payroll.php'))['deductions']]);

        $result = app(PayrollCalculator::class)->compute(25000, 1000);
        $this->assertSame(0.0, $result['total_deductions']);
        $this->assertSame(26000.0, $result['net']);
        $this->assertSame(['GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax'], $result['unset']);

        $this->actingAs($this->assistant)
            ->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")
            ->assertOk()
            ->assertJsonPath('unset_rules', ['GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax']);
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

    public function test_create_for_a_month_computes_one_row_per_rated_active_member(): void
    {
        $this->rate($this->researcher, 30000, 2000);
        $this->rate($this->assistant, 20000, 0);
        $inactive = AuditLogger::muted(fn () => $this->member('researcher'));
        $this->rate($inactive, 25000);
        AuditLogger::muted(fn () => $inactive->forceFill(['is_active' => false])->save());
        // The project leader has no rate: no row.

        $audit = AuditTrail::count();
        $response = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertCreated()
            ->assertJsonPath('message', 'Payroll created for 2 people.')
            ->assertJsonPath('month_label', 'November 2026')
            ->assertJsonCount(2, 'rows');

        $this->assertSame($audit + 2, AuditTrail::count());
        $this->assertSame(['created', 'created'], AuditTrail::latest('id')->take(2)->pluck('action')->all());

        $row = collect($response->json('rows'))->firstWhere('personnel.id', $this->researcher->id);
        $this->assertSame(32000, $row['gross']);
        $this->assertSame(4777.55, $row['deductions']);
        $this->assertSame(27222.45, $row['net']);
        $this->assertSame('draft', $row['workflow_status']);
        $this->assertCount(4, $row['deduction_items']);
        $this->assertSame(4, Payroll::findOrFail($row['id'])->deductions()->count());

        $breakdown = collect($response->json('deductionBreakdown'))->pluck('amount', 'type');
        $this->assertSame(2700 + 1800, (int) $breakdown['GSIS']); // 9% of 30,000 and of 20,000

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

    public function test_compute_refreshes_draft_rows_from_current_rates_only(): void
    {
        $this->rate($this->researcher, 30000, 2000);
        $this->rate($this->assistant, 20000);
        $this->createMonth();
        $verified = Payroll::where('personnel_id', $this->assistant->id)->firstOrFail();
        AuditLogger::muted(fn () => $verified->update(['workflow_status' => 'verified', 'status' => 'approved']));

        $this->rate($this->researcher, 40000, 0);
        $this->rate($this->assistant, 99999);

        $audit = AuditTrail::count();
        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])
            ->assertOk()
            ->assertJsonPath('message', 'Payroll computed for 1 entry.');

        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame('computed', AuditTrail::latest('id')->value('action'));
        $this->assertSame(40000.0, (float) Payroll::where('personnel_id', $this->researcher->id)->value('gross_pay'));
        $this->assertSame(20000.0, (float) $verified->fresh()->basic_rate); // verified: untouched
    }

    public function test_edit_recomputes_while_draft_and_is_refused_after_submit(): void
    {
        $payroll = $this->onePayroll();
        $audit = AuditTrail::count();

        $this->actingAs($this->assistant)
            ->patchJson("/api/payroll/{$payroll->id}", ['allowance' => 1000])
            ->assertOk()
            ->assertJsonPath('payroll.gross', 31000);
        $this->assertSame($audit + 1, AuditTrail::count());

        $this->postJson("/api/payroll/{$payroll->id}/submit")->assertOk();
        $this->patchJson("/api/payroll/{$payroll->id}", ['allowance' => 5])
            ->assertStatus(422)
            ->assertJsonPath('errors.workflow_status.0', 'This payroll cannot be edited while it is submitted.');
    }

    // ------------------------------------------------------------ workflow

    public function test_full_workflow_with_one_audit_entry_and_notification_per_hand_off(): void
    {
        $payroll = $this->onePayroll();
        $link = "/payroll?project={$this->project->id}&month={$this->month}";

        $audit = AuditTrail::count();
        $this->actingAs($this->assistant)->postJson("/api/payroll/{$payroll->id}/submit")
            ->assertOk()->assertJsonPath('payroll.workflow_status', 'submitted');
        $this->assertStep($audit, 'submitted', $payroll->id);
        $this->assertNotified($this->projectLeader, 'submitted', $link);

        $audit = AuditTrail::count();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll/{$payroll->id}/endorse")
            ->assertOk()->assertJsonPath('payroll.workflow_label', 'Approved by Project Leader');
        $this->assertStep($audit, 'endorsed', $payroll->id);
        $this->assertNotified($this->finance, 'endorsed', $link);
        $this->assertNotified($this->finance2, 'endorsed', $link);

        $audit = AuditTrail::count();
        $this->actingAs($this->finance)->postJson("/api/payroll/{$payroll->id}/verify")
            ->assertOk()
            ->assertJsonPath('payroll.workflow_status', 'verified')
            ->assertJsonPath('payroll.status', 'approved');
        $this->assertStep($audit, 'verified', $payroll->id);
        $this->assertNotified($this->assistant, 'verified', $link);
        $this->assertSame(4, DB::table('notifications')->count());

        // Payroll is not LIB spent.
        $this->assertSame(0.0, app(ProjectFinancialService::class)->spent($this->project));
    }

    public function test_return_and_reject_need_a_reason_and_go_back_to_the_preparer(): void
    {
        $payroll = $this->onePayroll();
        $this->actingAs($this->assistant)->postJson("/api/payroll/{$payroll->id}/submit")->assertOk();

        $this->actingAs($this->projectLeader)->postJson("/api/payroll/{$payroll->id}/return", ['reason' => ' '])->assertStatus(422);
        $this->postJson("/api/payroll/{$payroll->id}/return", ['reason' => 'Wrong allowance.'])
            ->assertOk()->assertJsonPath('payroll.workflow_status', 'returned')->assertJsonPath('payroll.return_reason', 'Wrong allowance.');
        $this->assertNotified($this->assistant, 'returned');

        $this->actingAs($this->assistant)->postJson("/api/payroll/{$payroll->id}/submit")->assertOk()->assertJsonPath('payroll.return_reason', null);
        $this->actingAs($this->projectLeader)->postJson("/api/payroll/{$payroll->id}/endorse")->assertOk();

        $this->actingAs($this->finance)->postJson("/api/payroll/{$payroll->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $audit = AuditTrail::count();
        $this->postJson("/api/payroll/{$payroll->id}/reject", ['reason' => 'Missing DTR.'])
            ->assertOk()->assertJsonPath('payroll.workflow_status', 'returned')->assertJsonPath('payroll.status', 'draft');
        $this->assertStep($audit, 'rejected', $payroll->id);
        $this->assertSame('Missing DTR.', AuditTrail::latest('id')->value('reason'));
        $this->assertNotified($this->assistant, 'rejected');
    }

    public function test_roles_maker_checker_and_own_pay_are_enforced(): void
    {
        // Prepared by Finance/Admin: that person cannot verify it.
        $byFinance = $this->onePayroll($this->finance);
        $this->actingAs($this->finance)->postJson("/api/payroll/{$byFinance->id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll/{$byFinance->id}/endorse")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/payroll/{$byFinance->id}/verify")
            ->assertForbidden()->assertJsonPath('message', 'You cannot verify or reject a payroll you computed.');
        $this->actingAs($this->finance2)->postJson("/api/payroll/{$byFinance->id}/verify")->assertOk();

        // Wrong roles at each step.
        $payroll = Payroll::query()->whereKeyNot($byFinance->id)->first() ?? $this->secondPayroll();
        foreach ([$this->assistant, $this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)->postJson("/api/payroll/{$payroll->id}/endorse")->assertForbidden();
        }
        foreach ([$this->projectLeader, $this->researcher] as $user) {
            $this->actingAs($user)->postJson("/api/payroll/{$payroll->id}/submit")->assertForbidden();
        }
        $this->actingAs($this->outsider)->postJson("/api/payroll/{$payroll->id}/submit")->assertForbidden();
        $this->actingAs($this->assistant)->postJson("/api/payroll/{$payroll->id}/verify")->assertForbidden();

        // Invalid transition: verify a draft.
        $this->actingAs($this->finance2)->postJson("/api/payroll/{$payroll->id}/verify")->assertStatus(422);
    }

    public function test_nobody_approves_or_verifies_their_own_pay(): void
    {
        $this->rate($this->projectLeader, 50000);
        $this->rate($this->finance2, 35000);
        $this->createMonth();
        $leaderPay = Payroll::where('personnel_id', $this->projectLeader->id)->firstOrFail();
        $financePay = Payroll::where('personnel_id', $this->finance2->id)->firstOrFail();

        foreach ([$leaderPay, $financePay] as $row) {
            $this->actingAs($this->assistant)->postJson("/api/payroll/{$row->id}/submit")->assertOk();
        }
        $this->actingAs($this->projectLeader)->postJson("/api/payroll/{$leaderPay->id}/endorse")
            ->assertForbidden()->assertJsonPath('message', 'You cannot approve or return your own payroll.');

        $this->postJson("/api/payroll/{$financePay->id}/endorse")->assertOk();
        $this->actingAs($this->finance2)->postJson("/api/payroll/{$financePay->id}/verify")
            ->assertForbidden()->assertJsonPath('message', 'You cannot verify or reject your own payroll.');
        $this->actingAs($this->finance)->postJson("/api/payroll/{$financePay->id}/verify")->assertOk();
    }

    public function test_payroll_files_use_documents(): void
    {
        $payroll = $this->onePayroll();
        $this->actingAs($this->assistant);
        $upload = fn (string $type) => $this->post("/api/payroll/{$payroll->id}/documents", [
            'file' => UploadedFile::fake()->create('payroll.pdf', 20, 'application/pdf'), 'document_type' => $type,
        ], ['Accept' => 'application/json']);

        $audit = AuditTrail::count();
        $upload('payroll_file')->assertCreated()->assertJsonPath('document.document_type', 'payroll_file');
        $this->assertSame($audit + 1, AuditTrail::count());
        $upload('receipt')->assertStatus(422)->assertJsonValidationErrors(['document_type']);

        $this->actingAs($this->projectLeader)->getJson("/api/payroll/{$payroll->id}/documents")->assertOk()->assertJsonCount(1, 'documents');
        $this->actingAs($this->outsider)->getJson("/api/payroll/{$payroll->id}/documents")->assertForbidden();
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
    }

    /** One draft payroll (researcher, 30,000) prepared by $by. */
    private function onePayroll(?User $by = null): Payroll
    {
        $this->rate($this->researcher, 30000);
        $this->createMonth($by);

        return Payroll::where('personnel_id', $this->researcher->id)->firstOrFail();
    }

    private function secondPayroll(): Payroll
    {
        $this->rate($this->projectLeader, 45000);
        $this->createMonth();

        return Payroll::where('personnel_id', $this->projectLeader->id)->firstOrFail();
    }

    private function assertStep(int $before, string $action, int $payrollId): void
    {
        $this->assertSame($before + 1, AuditTrail::count(), "one audit entry for {$action}");
        $entry = AuditTrail::latest('id')->firstOrFail();
        $this->assertSame([$action, 'Payroll', $payrollId, $this->project->id], [$entry->action, $entry->model_type, (int) $entry->model_id, (int) $entry->project_id]);
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

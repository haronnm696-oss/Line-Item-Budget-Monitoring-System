<?php

namespace Tests\Feature;

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

    public function test_compute_refreshes_every_row_while_the_batch_is_editable(): void
    {
        $this->rate($this->researcher, 30000, 2000);
        $this->rate($this->assistant, 20000);
        $this->createMonth();

        $this->rate($this->researcher, 40000, 0);
        $this->rate($this->assistant, 25000);

        $audit = AuditTrail::count();
        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])
            ->assertOk()
            ->assertJsonPath('message', 'Payroll computed for 2 entries.');
        $this->assertSame($audit + 2, AuditTrail::count()); // one per row changed
        $this->assertSame(40000.0, (float) Payroll::where('personnel_id', $this->researcher->id)->value('gross_pay'));
        $this->assertSame(25000.0, (float) Payroll::where('personnel_id', $this->assistant->id)->value('gross_pay'));

        // Not once the batch is submitted.
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
        $this->rate($this->researcher, 30000);
        $this->rate($this->assistant, 20000);
        $this->rate($this->projectLeader, 45000);
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

    public function test_payroll_files_attach_to_the_batch(): void
    {
        $this->onePayroll();
        $batch = $this->batch();
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

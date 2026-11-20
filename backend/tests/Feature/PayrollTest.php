<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Models\PayrollDeduction;
use App\Models\ProjectPersonnel;
use App\Services\PayrollService;
use App\Services\ProjectFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Payroll: honoraria from the project's personnel (Special Orders) for a
 * pay period of consecutive months, amount = monthly honorarium x months
 * effective, with NO deductions and NO allowances (net = gross); and the
 * batch workflow with maker-checker, one audit entry and one notification
 * per batch hand-off.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private LibCategory $ps;
    private string $month = '2026-11';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->ps = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS',
            'line_item_name' => 'Salaries of project staff', 'allocated_amount' => 900000,
        ]));
    }

    // ------------------------------------------------------------ pay period: honorarium x effective months

    public function test_a_row_is_the_monthly_honorarium_times_the_effective_months(): void
    {
        $this->appoint($this->researcher, 8800, '2025-10-01', '2025-12-31');

        $response = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2025-10', 'to' => '2025-12', 'lib_category_id' => $this->ps->id])
            ->assertCreated()
            ->assertJsonPath('month_label', 'October to December 2025')
            ->assertJsonPath('months', 3)
            ->assertJsonPath('batch.months', 3)
            ->assertJsonPath('batch.reference', 'PAY-2025-10-TO-2025-12')
            ->assertJsonCount(1, 'rows');

        $row = $response->json('rows.0');
        $this->assertSame('(8,800 x 3 months) = 26,400.00', $row['computation']);
        $this->assertSame(3, $row['months']);
        $this->assertEquals(8800, $row['basic']);
        $this->assertEquals(26400, $row['gross']);
        $this->assertEquals(26400, $row['net']);

        $batch = PayrollBatch::firstOrFail();
        $this->assertSame([2025, 10, 2025, 12], [$batch->pay_year, $batch->pay_month, $batch->period_end_year, $batch->period_end_month]);
        // Any month of the period opens the same batch.
        $this->getJson("/api/projects/{$this->project->id}/payroll?month=2025-11")->assertOk()
            ->assertJsonPath('batch.id', $batch->id)->assertJsonPath('month', '2025-10')->assertJsonPath('to', '2025-12');
    }

    public function test_someone_effective_from_november_is_billed_two_of_three_months(): void
    {
        $this->appoint($this->researcher, 8800, '2025-10-01', '2025-12-31');
        $this->appoint($this->assistant, 8800, '2025-11-01', '2026-06-30');
        $late = AuditLogger::muted(fn () => $this->member('researcher'));
        $this->appoint($late, 9000, '2026-01-01', '2026-06-30'); // not effective in the period

        $response = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2025-10', 'to' => '2025-12', 'lib_category_id' => $this->ps->id])
            ->assertCreated();

        $rows = collect($response->json('rows'))->keyBy('personnel.id');
        $this->assertSame('(8,800 x 2 months) = 17,600.00', $rows[$this->assistant->id]['computation']);
        $this->assertEquals(17600, $rows[$this->assistant->id]['gross']);
        $this->assertEquals(26400, $rows[$this->researcher->id]['gross']);
        $this->assertFalse($rows->has($late->id));

        $this->assertSame([[
            'user_id' => $late->id, 'name' => $late->name, 'reason' => 'not_effective', 'reason_text' => 'appointment not effective',
        ]], $response->json('not_included'));
        $this->assertStringContainsString("Not included: appointment not effective: {$late->name}.", $response->json('message'));
        $this->assertEquals(44000, $response->json('totals.gross'));
        $this->assertEquals(44000, $response->json('totals.net'));
    }

    public function test_a_changed_honorarium_in_the_period_is_paid_per_month(): void
    {
        $this->appoint($this->researcher, 8800, '2025-10-01', '2025-10-31');
        $this->appoint($this->researcher, 9000, '2025-11-01', '2025-12-31', false);

        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2025-10', 'to' => '2025-12', 'lib_category_id' => $this->ps->id])
            ->assertCreated()
            ->assertJsonPath('rows.0.computation', '(8,800 x 1 month) + (9,000 x 2 months) = 26,800.00')
            ->assertJsonPath('rows.0.months', 3);
    }

    public function test_net_equals_gross_with_no_deductions_or_allowance(): void
    {
        $payroll = $this->onePayroll();

        $this->assertSame(30000.0, (float) $payroll->gross_pay);
        $this->assertSame(30000.0, (float) $payroll->net_pay);
        $this->assertSame(0.0, (float) $payroll->total_deductions);
        $this->assertSame(0.0, (float) $payroll->allowance);
        $this->assertSame(0, PayrollDeduction::count());

        $run = $this->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")->assertOk();
        $this->assertFalse($run->json('legacy_amounts'));
        foreach (['deductionBreakdown', 'unset_rules', 'rates_label'] as $gone) {
            $this->assertArrayNotHasKey($gone, $run->json());
        }
        foreach (['allowance', 'deductions', 'deduction_items'] as $gone) {
            $this->assertArrayNotHasKey($gone, $run->json('rows.0'));
        }
        $this->assertSame(['gross', 'net', 'recorded_deductions'], array_keys($run->json('totals')));
    }

    public function test_no_allowance_can_be_entered(): void
    {
        $payroll = $this->onePayroll();

        $this->actingAs($this->assistant)
            ->patchJson("/api/payroll/{$payroll->id}", ['allowance' => 1000])
            ->assertStatus(422)
            ->assertJsonPath('errors.allowance.0', 'Project honoraria have no allowance.');
        $this->assertSame(0.0, (float) $payroll->fresh()->allowance);
        $this->assertSame(30000.0, (float) $payroll->fresh()->gross_pay);
    }

    public function test_no_deduction_setting_is_needed_to_submit(): void
    {
        $this->assertFalse(file_exists(config_path('payroll.php')));
        $this->assertNull(config('payroll'));
        $this->onePayroll();

        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$this->batch()->id}/submit")
            ->assertOk()->assertJsonPath('batch.workflow_status', 'submitted');
    }

    public function test_overlapping_periods_are_refused(): void
    {
        $this->appoint($this->researcher, 8800, '2025-01-01', '2026-12-31');
        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2025-10', 'to' => '2025-12', 'lib_category_id' => $this->ps->id])
            ->assertCreated();

        foreach ([['2025-11', '2025-11'], ['2025-12', '2026-02'], ['2025-09', '2025-10'], ['2025-08', '2026-01'], ['2025-10', '2025-11']] as [$from, $to]) {
            $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $from, 'to' => $to, 'lib_category_id' => $this->ps->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['period']);
        }
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2025-11', 'to' => '2025-11', 'lib_category_id' => $this->ps->id])
            ->assertJsonPath('errors.period.0', 'November 2025 overlaps the October to December 2025 payroll of this project. Each month is paid in one payroll only: choose months that no other payroll covers.');
        // The page says so before preparing.
        $this->getJson("/api/projects/{$this->project->id}/payroll?month=2025-09&to=2025-10")->assertOk()
            ->assertJsonPath('batch', null)
            ->assertJsonPath('overlap_text', 'September to October 2025 overlaps the October to December 2025 payroll of this project. Each month is paid in one payroll only: choose months that no other payroll covers.');

        // The next quarter, right after, is fine.
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2026-01', 'to' => '2026-03', 'lib_category_id' => $this->ps->id])
            ->assertCreated();
        $this->assertSame(2, PayrollBatch::count());
        $this->assertSame(2, Payroll::count());

        // The last month cannot come first; at most 12 months.
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2026-06', 'to' => '2026-04', 'lib_category_id' => $this->ps->id])
            ->assertStatus(422)->assertJsonPath('errors.to.0', 'The last month cannot be before the first month.');
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2026-04', 'to' => '2027-04', 'lib_category_id' => $this->ps->id])
            ->assertStatus(422)->assertJsonPath('errors.to.0', 'A payroll covers at most 12 consecutive months.');
    }

    public function test_verified_batches_stay_as_recorded_and_draft_ones_recompute_without_deductions_or_allowances(): void
    {
        $this->rate($this->researcher, 30000);
        $legacy = function (string $stage, int $month) {
            return AuditLogger::muted(function () use ($stage, $month) {
                $batch = PayrollBatch::create(['project_id' => $this->project->id, 'pay_year' => 2026, 'pay_month' => $month, 'prepared_by' => $this->assistant->id, 'workflow_status' => $stage]);
                $row = Payroll::create([
                    'project_id' => $this->project->id, 'payroll_batch_id' => $batch->id, 'lib_category_id' => $this->ps->id,
                    'personnel_id' => $this->researcher->id, 'computed_by' => $this->assistant->id,
                    'pay_month' => $month, 'pay_year' => 2026, 'basic_rate' => 30000, 'allowance' => 1000,
                    'gross_pay' => 31000, 'total_deductions' => 3100, 'net_pay' => 27900,
                    'status' => Payroll::STATUS_FOR_WORKFLOW[$stage], 'workflow_status' => $stage,
                ]);
                PayrollDeduction::create(['payroll_id' => $row->id, 'deduction_type' => 'GSIS', 'amount' => 3100]);

                return $batch;
            });
        };
        $verified = $legacy('verified', 9);
        $draft = $legacy('draft', 10);
        $this->payrollFileFor($draft, $this->assistant);

        // Verified: unchanged, shown as recorded.
        $this->actingAs($this->assistant)->getJson("/api/projects/{$this->project->id}/payroll?month=2026-09")->assertOk()
            ->assertJsonPath('legacy_amounts', true)
            ->assertJsonPath('batch.needs_recompute', false)
            ->assertJsonPath('rows.0.computation', '(30,000 x 1 month) + allowance 1,000.00 = 31,000.00')
            ->assertJsonPath('rows.0.recorded_deductions', 3100);
        $this->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => '2026-09'])->assertStatus(422);
        $row = $verified->rows()->firstOrFail();
        $this->assertSame([31000.0, 1000.0, 3100.0, 27900.0], [(float) $row->gross_pay, (float) $row->allowance, (float) $row->total_deductions, (float) $row->net_pay]);
        $this->assertSame(1, $row->deductions()->count());
        $register = $this->getJson("/api/projects/{$this->project->id}/payroll-register")->assertOk();
        $this->assertTrue($register->json('has_recorded_deductions'));
        $this->assertEquals(27900, $register->json('totals.net'));

        // Draft: must be recomputed before it can be submitted; then net = gross.
        $this->getJson("/api/projects/{$this->project->id}/payroll?month=2026-10")->assertJsonPath('batch.needs_recompute', true);
        $this->postJson("/api/payroll-batches/{$draft->id}/submit")->assertStatus(422)
            ->assertJsonPath('errors.rows.0', PayrollService::NEEDS_RECOMPUTE);
        $this->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => '2026-10'])->assertOk()
            ->assertJsonPath('legacy_amounts', false)
            ->assertJsonPath('batch.needs_recompute', false)
            ->assertJsonPath('rows.0.computation', '(30,000 x 1 month) = 30,000.00');
        $row = $draft->rows()->firstOrFail();
        $this->assertSame([30000.0, 0.0, 0.0, 30000.0], [(float) $row->gross_pay, (float) $row->allowance, (float) $row->total_deductions, (float) $row->net_pay]);
        $this->assertSame(0, $row->deductions()->count());
        $this->postJson("/api/payroll-batches/{$draft->id}/submit")->assertOk();
    }

    // ------------------------------------------------------------ create from personnel
    // Personnel records (who is paid, and how much): PersonnelTest.

    public function test_compute_names_personnel_with_no_honorarium_and_never_pays_them_zero(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->assistant, 0); // 0.00: named, never a 0.00 row
        $this->createMonth();

        $run = $this->actingAs($this->assistant)->getJson("/api/projects/{$this->project->id}/payroll?month={$this->month}")->assertOk();
        // Members without a personnel record (Job Order, professional
        // services) are not paid through payroll and are not listed.
        $this->assertSame([['user_id' => $this->assistant->id, 'name' => $this->assistant->name, 'reason' => 'zero', 'reason_text' => 'honorarium 0.00']], $run->json('not_included'));

        $response = $this->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])->assertOk();
        $this->assertSame("Payroll computed for 1 entry. Not included (honorarium 0.00): {$this->assistant->name}.", $response->json('message'));
        $this->assertSame(1, Payroll::count());
        $this->assertSame(0, Payroll::where('gross_pay', 0)->count());
    }

    public function test_only_personnel_whose_period_covers_the_month_are_paid(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->assistant, 20000);
        AuditLogger::muted(fn () => \App\Models\ProjectPersonnel::where('user_id', $this->assistant->id)
            ->update(['effective_from' => '2026-01-01', 'effective_to' => '2026-10-31']));

        $this->createMonth();
        $this->assertSame([$this->researcher->id], Payroll::pluck('personnel_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_recompute_removes_rows_whose_rate_became_zero_or_not_set(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->assistant, 20000);
        $this->rate($this->projectLeader, 50000);
        $this->createMonth();
        $this->assertSame(3, Payroll::count());

        // The assistant's honorarium becomes 0.00 and the Project Leader's record is removed.
        $this->rate($this->assistant, 0);
        $this->setHonorarium($this->projectLeader, null);
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
        $this->assertStringStartsWith('Payroll computed for 1 entry.', $message);
        $this->assertStringContainsString("Not included (honorarium 0.00): {$this->assistant->name}.", $message);
        $this->assertStringContainsString("Removed from this payroll: {$this->projectLeader->name}.", $message);
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
        $this->rate($this->researcher, 40000);
        $this->rate($this->assistant, 20000);
        $inactive = AuditLogger::muted(fn () => $this->member('researcher'));
        $this->rate($inactive, 25000);
        AuditLogger::muted(fn () => $inactive->forceFill(['is_active' => false])->save());
        // The project leader is not personnel: no row, and not named.

        $audit = AuditTrail::count();
        $response = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertCreated()
            ->assertJsonPath('month_label', 'November 2026')
            ->assertJsonCount(2, 'rows');
        $this->assertSame('Payroll created for 2 people.', $response->json('message'));

        $this->assertSame($audit + 2, AuditTrail::count());
        $this->assertSame(['created', 'created'], AuditTrail::latest('id')->take(2)->pluck('action')->all());

        // Honorarium only, one month: no deductions, no allowance, net = gross.
        $row = collect($response->json('rows'))->firstWhere('personnel.id', $this->researcher->id);
        $this->assertEquals(40000, $row['gross']);
        $this->assertEquals(40000, $row['net']);
        $this->assertSame('(40,000 x 1 month) = 40,000.00', $row['computation']);
        $this->assertSame('draft', $row['workflow_status']);
        $this->assertSame(0, Payroll::findOrFail($row['id'])->deductions()->count());

        // A second create for the same month adds nothing.
        $this->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.month.0', 'Payroll for November 2026 already exists for all personnel.');
        $this->assertSame(2, Payroll::count());
    }

    public function test_create_is_refused_without_rates_or_for_the_wrong_role_or_line_item(): void
    {
        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $this->month, 'lib_category_id' => $this->ps->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.month.0', 'No personnel with an appointment effective in November 2026 and a monthly honorarium. The Program Leader adds personnel on the project page.');

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
        $this->rate($this->researcher, 30000);
        $this->rate($this->assistant, 20000);
        $this->createMonth();

        $this->rate($this->researcher, 40000);
        $this->rate($this->assistant, 25000);

        $audit = AuditTrail::count();
        $response = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll/compute", ['month' => $this->month])
            ->assertOk()
            ->assertJsonPath('rated_members', 2);
        $this->assertSame('Payroll computed for 2 entries.', $response->json('message'));
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
            ->patchJson("/api/payroll/{$payroll->id}", ['basic_rate' => 31000])
            ->assertOk()
            ->assertJsonPath('payroll.gross', 31000)
            ->assertJsonPath('payroll.net', 31000);
        $this->assertSame($audit + 1, AuditTrail::count());

        $batch = $this->batch();
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $this->patchJson("/api/payroll/{$payroll->id}", ['basic_rate' => 5])
            ->assertStatus(422)
            ->assertJsonPath('errors.workflow_status.0', 'This payroll cannot be edited while it is submitted.');

        // Returned: editable again.
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/return", ['reason' => 'Check the honorarium.'])->assertOk();
        $this->actingAs($this->assistant)->patchJson("/api/payroll/{$payroll->id}", ['basic_rate' => 30500])->assertOk();
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
        // A verified batch is spent: 30,000 + 20,000 + 45,000.
        $this->assertSame(95000.0, app(ProjectFinancialService::class)->spent($this->project));
        $this->assertSame(95000.0, app(ProjectFinancialService::class)->spentPayroll($this->project));

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
        $this->postJson("/api/payroll-batches/{$batch->id}/return", ['reason' => 'Wrong honorarium.'])
            ->assertOk()->assertJsonPath('batch.workflow_status', 'returned')->assertJsonPath('batch.return_reason', 'Wrong honorarium.');
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

    /** A personnel record (Special Order) for $user; $replace removes their other records first. */
    private function appoint(User $user, float $monthly, string $from, string $to, bool $replace = true): void
    {
        AuditLogger::muted(function () use ($user, $monthly, $from, $to, $replace) {
            if ($replace) {
                ProjectPersonnel::where('project_id', $this->project->id)->where('user_id', $user->id)->delete();
            }
            ProjectPersonnel::create([
                'project_id' => $this->project->id, 'user_id' => $user->id, 'position' => 'Project Staff',
                'special_order_no' => 'SO No. 0002 s. 2025', 'effective_from' => $from, 'effective_to' => $to,
                'monthly_honorarium' => $monthly,
            ]);
        });
    }

    /** The person's personnel record with this monthly honorarium (project period). */
    private function rate(User $user, float $monthly): void
    {
        $this->setHonorarium($user, $monthly);
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

<?php

namespace Tests\Feature;

use App\Models\LibCategory;
use App\Models\PayrollBatch;
use App\Models\ProjectPersonnel;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Data for the printed reports' official layout: the Expense Ledger lists
 * verified expenses and verified payroll only, and notes the records still
 * waiting for verification (ledger_pending); the signature blocks name the
 * project's Finance/Admin and Project Leader, and the Program Leader.
 */
class ReportTemplateDataTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
    }

    public function test_the_ledger_lists_verified_rows_and_ledger_pending_holds_only_records_waiting_for_verification(): void
    {
        $verified = $this->expenseIn('verified', null, true, ['amount' => 1000, 'verified_by' => $this->finance->id]);
        $this->expenseIn('submitted', null, true, ['amount' => 200]);
        $this->expenseIn('endorsed', null, true, ['amount' => 300]);
        $this->expenseIn('draft', null, true, ['amount' => 5000]);     // not submitted: not pending verification
        $this->expenseIn('returned', null, true, ['amount' => 7000]);  // returned: not pending verification

        $payload = $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}")->assertOk();

        // The page's ledger rows keep their status; only "Verified" rows are printed.
        $printed = collect($payload->json('expenses'))->where('status', 'Verified');
        $this->assertSame([$verified->id], $printed->pluck('id')->all());

        $pending = collect($payload->json('ledger_pending'));
        $this->assertCount(2, $pending);
        $this->assertEquals(500, $pending->sum('amount'));
        $this->assertSame(['expense'], $pending->pluck('kind')->unique()->values()->all());
        $this->assertSame('MOOE', $pending->first()['type']);
        $this->assertSame('2026-11-05', $pending->first()['date']);
    }

    public function test_ledger_pending_is_empty_when_nothing_waits_and_counts_waiting_payroll_until_verified(): void
    {
        $this->expenseIn('verified', null, true, ['verified_by' => $this->finance->id]);
        $url = "/api/projects/{$this->project->id}";
        $this->assertSame([], $this->actingAs($this->finance)->getJson($url)->assertOk()->json('ledger_pending'));

        $honoraria = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS',
            'line_item_name' => 'Honoraria of project staff', 'allocated_amount' => 50000,
        ]));
        AuditLogger::muted(fn () => ProjectPersonnel::create([
            'project_id' => $this->project->id, 'user_id' => $this->researcher->id, 'position' => 'Project Staff',
            'lib_category_id' => $honoraria->id, 'special_order_no' => 'SO No. 0003 s. 2026',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'monthly_honorarium' => 30000,
        ]));
        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => '2026-11', 'lib_category_id' => $honoraria->id])
            ->assertCreated();
        $batch = PayrollBatch::where('project_id', $this->project->id)->firstOrFail();
        $this->payrollFileFor($batch, $this->assistant);

        // A draft batch is not waiting for verification yet.
        $this->assertSame([], $this->actingAs($this->finance)->getJson($url)->json('ledger_pending'));

        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $pending = $this->actingAs($this->finance)->getJson($url)->json('ledger_pending');
        $this->assertCount(1, $pending);
        $this->assertSame(['kind' => 'payroll', 'date' => '2026-11-30', 'type' => 'PS'], array_intersect_key($pending[0], array_flip(['kind', 'date', 'type'])));
        $this->assertEquals(30000, $pending[0]['amount']);

        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk();
        $after = $this->getJson($url)->assertOk();
        $this->assertSame([], $after->json('ledger_pending'));
        $this->assertTrue(collect($after->json('expenses'))->contains(fn ($row) => $row['kind'] === 'payroll' && $row['status'] === 'Verified'));
    }

    public function test_signatories_name_the_first_active_finance_admin_and_the_project_leader(): void
    {
        $url = "/api/projects/{$this->project->id}";
        $this->actingAs($this->assistant)->getJson($url)->assertOk()
            ->assertJsonPath('signatories.finance_admin', $this->finance->name)
            ->assertJsonPath('signatories.project_leader', $this->projectLeader->name);

        // The first Finance/Admin deactivated: the next active one.
        AuditLogger::muted(fn () => $this->finance->update(['is_active' => false]));
        $this->getJson($url)->assertJsonPath('signatories.finance_admin', $this->finance2->name);

        // None active: null (a blank "Finance/Admin Staff" line).
        AuditLogger::muted(fn () => $this->finance2->update(['is_active' => false]));
        $this->getJson($url)->assertJsonPath('signatories.finance_admin', null);
    }

    public function test_report_signatories_names_the_program_leader(): void
    {
        $this->actingAs($this->finance)->getJson('/api/report-signatories')->assertOk()
            ->assertExactJson(['program_leader' => $this->programLeader->name]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/report-signatories')->assertUnauthorized();
    }
}

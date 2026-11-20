<?php

namespace Tests\Feature;

use App\Models\LibCategory;
use App\Models\PayrollBatch;
use App\Models\ProjectPersonnel;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProjectFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * A verified payroll batch counts as spent (charged to each person's linked
 * PS line item, funder share); a waiting one is pending; a batch may not
 * charge a line item more than its available amount; and honoraria lines
 * (personnel linked) take no expenses, so nothing is counted twice.
 */
class PayrollSpentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private LibCategory $honoraria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->honoraria = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS',
            'line_item_name' => 'Honoraria of project staff', 'allocated_amount' => 50000,
        ]));
    }

    public function test_a_waiting_payroll_is_pending_and_a_verified_one_is_spent_on_its_line_item(): void
    {
        $this->appoint($this->researcher, 30000);
        $batch = $this->prepare();
        $line = fn () => collect($this->actingAs($this->assistant)->getJson($this->url('balancing'))->assertOk()->json('line_items'))
            ->firstWhere('id', $this->honoraria->id);

        $this->assertSame([0, 0, 50000], [$line()['spent'], $line()['pending'], $line()['available']]);

        $this->actingAs($this->assistant)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $this->assertSame([0, 30000, 30000, 20000], [$line()['spent'], $line()['pending'], $line()['pending_payroll'], $line()['available']]);

        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();
        $this->assertSame(30000, $line()['pending']);

        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk();
        $this->assertSame([30000, 30000, 0, 20000], [$line()['spent'], $line()['spent_payroll'], $line()['pending'], $line()['available']]);
        $this->assertSame(30000.0, $this->honoraria->fresh()->spentAmount());

        // Listed with the spent transactions, and in the monthly chart and PS split.
        $transactions = $this->actingAs($this->researcher)->getJson($this->url('transactions'))->assertOk();
        $transactions->assertJsonPath('meta.total', 1)->assertJsonPath('meta.amount', 30000)
            ->assertJsonPath('transactions.0.kind', 'payroll')
            ->assertJsonPath('transactions.0.line_item', 'Honoraria of project staff');
        $summary = $this->getJson($this->url('budget-summary'))->assertOk();
        $this->assertEquals(30000, $summary->json('category_summary.ps.spent'));
        $this->assertEquals(30000, collect($summary->json('monthly_expenses'))->sum('amount'));
    }

    public function test_a_batch_over_the_available_amount_of_its_line_item_is_refused(): void
    {
        $this->appoint($this->researcher, 30000);
        $this->appoint($this->assistant, 25000);
        $batch = $this->prepare(); // 55,000 on a 50,000 line

        $message = 'The November 2026 payroll charges ₱55,000.00 to Honoraria of project staff, more than the ₱50,000.00 available. Ask the Project Leader for a realignment, or take people out of this payroll.';
        $this->actingAs($this->assistant)->getJson($this->url('payroll?month=2026-11'))->assertOk()
            ->assertJsonPath('batch.over_balance', [$message]);
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")
            ->assertStatus(422)->assertJsonPath('errors.amount.0', $message);
        $this->assertSame('draft', $batch->fresh()->workflow_status);

        // Within the balance once one person is taken out.
        $this->appoint($this->assistant, 0);
        $this->postJson($this->url('payroll/compute'), ['month' => '2026-11'])->assertOk()->assertJsonPath('batch.over_balance', []);
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();

        // The balance shrank before verification (e.g. an older allocation change): refused then too.
        AuditLogger::muted(fn () => $this->honoraria->update(['allocated_amount' => 20000]));
        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->assertSame('endorsed', $batch->fresh()->workflow_status);
        $this->assertSame(0.0, app(ProjectFinancialService::class)->spentPayroll($this->project));
    }

    public function test_honoraria_lines_are_refused_for_expenses(): void
    {
        $this->appoint($this->researcher, 30000); // links the honoraria line
        $otherPs = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS', 'line_item_name' => 'Professional services', 'allocated_amount' => 10000,
        ]));

        // The balancing query flags it, so Record expense leaves it out.
        $rows = collect($this->actingAs($this->assistant)->getJson($this->url('balancing'))->json('line_items'))->keyBy('id');
        $this->assertTrue($rows[$this->honoraria->id]['honoraria']);
        $this->assertFalse($rows[$otherPs->id]['honoraria']);
        $this->assertFalse($rows[$this->lineItem->id]['honoraria']);

        $refused = 'Honoraria are paid through Payroll. Choose another line item for this expense.';
        $this->postJson($this->url('expenses'), $this->expensePayload(['lib_category_id' => $this->honoraria->id]))
            ->assertStatus(422)->assertJsonPath('errors.lib_category_id.0', $refused);

        // Another PS line (no personnel) and MOOE are fine; moving one onto the honoraria line is not.
        $this->postJson($this->url('expenses'), $this->expensePayload(['lib_category_id' => $otherPs->id, 'amount' => 100]))->assertCreated();
        $id = $this->postJson($this->url('expenses'), $this->expensePayload())->assertCreated()->json('expense.id');
        $this->patchJson("/api/expenses/{$id}", ['lib_category_id' => $this->honoraria->id])
            ->assertStatus(422)->assertJsonPath('errors.lib_category_id.0', $refused);

        // An older draft on that line cannot be submitted either.
        $legacy = $this->expenseIn('draft', $this->assistant, true, ['lib_category_id' => $this->honoraria->id]);
        $this->postJson("/api/expenses/{$legacy->id}/submit")
            ->assertStatus(422)->assertJsonPath('errors.lib_category_id.0', $refused);
    }

    // ------------------------------------------------------------ helpers

    private function url(string $path): string
    {
        return "/api/projects/{$this->project->id}/{$path}";
    }

    /** A Special Order for the person on the honoraria line (2026), replacing any other. */
    private function appoint(User $user, float $monthly): void
    {
        AuditLogger::muted(function () use ($user, $monthly) {
            ProjectPersonnel::where('project_id', $this->project->id)->where('user_id', $user->id)->delete();
            ProjectPersonnel::create([
                'project_id' => $this->project->id, 'user_id' => $user->id, 'position' => 'Project Staff',
                'lib_category_id' => $this->honoraria->id, 'special_order_no' => 'SO No. 0003 s. 2026',
                'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'monthly_honorarium' => $monthly,
            ]);
        });
    }

    /** The November 2026 batch prepared by the Research Assistant, with its payroll file. */
    private function prepare(): PayrollBatch
    {
        $this->actingAs($this->assistant)
            ->postJson($this->url('payroll'), ['month' => '2026-11', 'lib_category_id' => $this->honoraria->id])
            ->assertCreated();
        $batch = PayrollBatch::where('project_id', $this->project->id)->firstOrFail();
        $this->payrollFileFor($batch, $this->assistant);

        return $batch;
    }
}

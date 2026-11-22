<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\ProjectUser;
use App\Services\AuditLogger;
use App\Services\ProjectFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Finance/Admin follows MSU's process: it verifies expenses and payroll
 * against their documents and does not encode expenses (the Research
 * Assistant and the Project Leader do). Older expenses a Finance/Admin
 * recorded keep working, and the Finance Overview's figures all come from
 * ProjectFinancialService.
 */
class FinanceVerifiesOnlyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    // ------------------------------------------------------------ figures

    /**
     * RP-2026-021 showed ₱365.00 for Total spent and Verified this month
     * beside ₱500.00 in Recently verified and Transaction history. The API
     * gives one number for one verified expense of ₱500.00 (another
     * project's ₱365.00 never leaks in), on every load.
     */
    public function test_every_overview_figure_matches_one_verified_expense_of_500(): void
    {
        $this->verifiedExpense(500, $this->project);
        $this->verifiedExpense(365, $this->otherProject);
        $spent = app(ProjectFinancialService::class)->spent($this->project);
        $this->assertSame(500.0, $spent);

        $this->actingAs($this->finance);
        foreach ([1, 2] as $load) {
            $overview = $this->getJson("/api/projects/{$this->project->id}/finance-overview")->assertOk();
            $this->assertEquals(500, $overview->json('kpis.total_spent'), "load {$load}: Total spent");
            $this->assertEquals(500, $overview->json('kpis.verified_this_month'), "load {$load}: Verified this month");
            $this->assertSame(1, $overview->json('kpis.verified_this_month_expenses'));
            $this->assertEquals(500, $overview->json('recently_verified.0.amount'), "load {$load}: Recently verified");
        }

        $history = $this->getJson("/api/projects/{$this->project->id}/transactions")->assertOk();
        $this->assertEquals(500, $history->json('meta.amount'));
        $this->assertEquals(500, $history->json('transactions.0.amount'));
    }

    // ------------------------------------------------------------ recording

    public function test_finance_admin_cannot_record_an_expense_but_the_assistant_and_project_leader_can(): void
    {
        $url = "/api/projects/{$this->project->id}/expenses";

        $this->actingAs($this->finance)->postJson($url, $this->expensePayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Only a Research Assistant or the Project Leader of this project can record expenses. Finance/Admin verifies them.');
        $this->assertSame(0, Expense::where('recorded_by', $this->finance->id)->count());

        $this->actingAs($this->assistant)->postJson($url, $this->expensePayload())->assertCreated();
        $this->actingAs($this->projectLeader)->postJson($url, $this->expensePayload())->assertCreated();
    }

    public function test_a_returned_expense_finance_recorded_can_be_edited_and_resubmitted_by_its_recorder_only(): void
    {
        $own = $this->expenseIn('returned', $this->finance, true, ['return_reason' => 'Wrong amount']);
        $theirs = $this->expenseIn('returned', $this->assistant);

        $this->actingAs($this->finance)
            ->patchJson("/api/expenses/{$own->id}", $this->expensePayload(['amount' => 1300]))
            ->assertOk();
        $this->postJson("/api/expenses/{$own->id}/submit")
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'submitted');

        // Someone else's expense: Finance/Admin verifies it, never edits it.
        $this->patchJson("/api/expenses/{$theirs->id}", $this->expensePayload())->assertForbidden();
        $this->postJson("/api/expenses/{$theirs->id}/submit")->assertForbidden();
    }

    // ------------------------------------------------------------ verification

    public function test_another_finance_admin_verifies_an_expense_finance_recorded(): void
    {
        $expense = $this->expenseIn('submitted', $this->finance);

        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$expense->id}/endorse")->assertOk();
        $this->assertNull($expense->fresh()->approval_request_id);

        $this->actingAs($this->finance)->postJson("/api/expenses/{$expense->id}/verify")->assertForbidden();
        $this->actingAs($this->finance2)->postJson("/api/expenses/{$expense->id}/verify")
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'verified');
    }

    public function test_with_no_other_finance_admin_the_program_leader_verifies_an_older_finance_recorded_expense(): void
    {
        $this->removeFinance2();
        $expense = $this->expenseIn('submitted', $this->finance);

        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$expense->id}/endorse")->assertOk();
        $approval = $expense->fresh()->pendingEscalation();
        $this->assertNotNull($approval, 'The verify step should go to the Program Leader.');
        $this->assertSame('expense', $approval->type);
        $this->assertSame($this->finance->id, (int) $approval->requested_by);

        // The recorder cannot verify their own expense.
        $this->actingAs($this->finance)->postJson("/api/expenses/{$expense->id}/verify")->assertForbidden();
        $queue = $this->getJson("/api/projects/{$this->project->id}/verification-queue")->assertOk();
        $this->assertFalse($queue->json('items.0.can_verify'));

        $this->actingAs($this->programLeader)
            ->getJson("/api/approvals/{$approval->id}")
            ->assertOk()
            ->assertJsonPath('approval.details.kind', 'expense')
            ->assertJsonPath('approval.can_decide', true);
        $this->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved'])->assertOk();

        $expense->refresh();
        $this->assertSame('verified', $expense->workflow_status);
        $this->assertSame($this->programLeader->id, (int) $expense->verified_by);
        $this->assertSame(1200.0, app(ProjectFinancialService::class)->spent($this->project));
        $this->assertSame('approved', $approval->fresh()->status);
    }

    public function test_an_expense_already_waiting_is_sent_to_the_program_leader_by_the_reroute_command(): void
    {
        $this->removeFinance2();
        // Older data: approved before this change, with no one to verify it.
        $expense = $this->expenseIn('endorsed', $this->finance, true, ['pl_reviewed_by' => $this->projectLeader->id, 'pl_reviewed_at' => now()]);

        $this->artisan('workflow:reroute-leader-steps', ['--apply' => true, '--as' => $this->programLeader->email])->assertSuccessful();

        $approval = $expense->fresh()->pendingEscalation();
        $this->assertNotNull($approval);
        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'returned', 'remarks' => 'Receipt is unreadable'])
            ->assertOk();
        $this->assertSame('returned', $expense->fresh()->workflow_status);
        $this->assertSame('Receipt is unreadable', $expense->fresh()->return_reason);

        // Running it again changes nothing.
        $this->artisan('workflow:reroute-leader-steps', ['--apply' => true, '--as' => $this->programLeader->email])->assertSuccessful();
        $this->assertSame(1, ApprovalRequest::where('type', 'expense')->count());
    }

    public function test_removing_the_other_finance_admin_sends_a_waiting_expense_to_the_program_leader(): void
    {
        $expense = $this->expenseIn('endorsed', $this->finance, true, ['pl_reviewed_by' => $this->projectLeader->id, 'pl_reviewed_at' => now()]);

        $this->actingAs($this->programLeader)
            ->deleteJson("/api/program/assignments/{$this->finance2->id}/{$this->project->id}", ['reason' => 'Moved to another office'])
            ->assertSuccessful();

        $this->assertNotNull($expense->fresh()->pendingEscalation());
    }

    // ------------------------------------------------------------ transaction history

    public function test_the_missing_receipt_filter_lists_only_verified_expenses_without_a_receipt(): void
    {
        $missing = $this->verifiedExpense(800, $this->project, false);
        $this->verifiedExpense(500, $this->project);

        $this->actingAs($this->finance);
        $all = $this->getJson("/api/projects/{$this->project->id}/transactions")->assertOk();
        $this->assertSame(2, $all->json('meta.total'));

        $filtered = $this->getJson("/api/projects/{$this->project->id}/transactions?receipt=missing")->assertOk();
        $this->assertSame(1, $filtered->json('meta.total'));
        $this->assertSame($missing->id, $filtered->json('transactions.0.id'));
        $this->assertSame(0, $filtered->json('transactions.0.receipts_count'));

        // Same count as the Overview's "Missing receipts" KPI.
        $this->assertSame(1, $this->getJson("/api/projects/{$this->project->id}/finance-overview")->json('kpis.missing_receipts'));

        $this->getJson("/api/projects/{$this->project->id}/transactions?receipt=other")->assertStatus(422);
    }

    // ------------------------------------------------------------ helpers

    private function verifiedExpense(float $amount, $project, bool $withReceipt = true): Expense
    {
        $lineItem = $project->is($this->project) ? $this->lineItem : AuditLogger::muted(fn () => \App\Models\LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => 'Supplies',
            'allocated_amount' => 500000,
        ]));

        return $this->expenseIn('verified', $this->assistant, $withReceipt, [
            'project_id' => $project->id,
            'lib_category_id' => $lineItem->id,
            'amount' => $amount,
            'verified_by' => $this->finance->id,
            'verified_at' => now(),
            'pl_reviewed_by' => $this->projectLeader->id,
        ]);
    }

    private function removeFinance2(): void
    {
        AuditLogger::muted(fn () => ProjectUser::where('project_id', $this->project->id)->where('user_id', $this->finance2->id)->delete());
    }
}

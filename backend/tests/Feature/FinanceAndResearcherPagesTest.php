<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\PayrollBatch;
use App\Models\ProjectUser;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Finance/Admin pages (Overview, Verification queue, Payroll list, Notify
 * RA), the Researcher's read-only summary, and the numbers every role sees
 * for the same project.
 */
class FinanceAndResearcherPagesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private LibCategory $ps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        config(['payroll.deductions' => [], 'documents.disk' => 'local']);
        Storage::fake('local');
        $this->ps = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS',
            'line_item_name' => 'Salaries of project staff', 'allocated_amount' => 900000,
        ]));
    }

    private function url(string $path): string
    {
        return "/api/projects/{$this->project->id}/{$path}";
    }

    // ---------------------------------------------------------------- To verify

    public function test_project_leader_approved_items_equal_finance_to_verify_and_the_badge(): void
    {
        // Two expenses and one payroll batch approved by the Project Leader, through the API.
        $a = $this->submittedExpense();
        $b = $this->submittedExpense();
        $this->submittedExpense(); // still waiting for the Project Leader
        $batch = $this->submittedBatch();
        $this->actingAs($this->projectLeader);
        $this->postJson("/api/expenses/{$a}/endorse")->assertOk();
        $this->postJson("/api/expenses/{$b}/endorse")->assertOk();
        $this->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();

        $approvedByLeader = collect($this->getJson($this->url('review-queue?tab=approved'))->assertOk()->json('items'))
            ->where('workflow_status', 'endorsed')->count();

        $this->actingAs($this->finance);
        $overview = $this->getJson($this->url('finance-overview'))->assertOk();
        $badges = $this->getJson($this->url('finance-badges'))->assertOk();
        $queue = $this->getJson($this->url('verification-queue'))->assertOk();

        $this->assertSame(3, $approvedByLeader);
        $this->assertSame($approvedByLeader, $overview->json('kpis.to_verify'));
        $this->assertSame($overview->json('kpis.to_verify'), $badges->json('verification'));
        $this->assertSame($badges->json('verification'), $queue->json('counts.waiting'));
        $this->assertCount(3, $queue->json('items'));
        $this->assertCount(3, $overview->json('queue'));

        // Only Finance/Admin of the project open these.
        foreach ([$this->assistant, $this->projectLeader, $this->researcher, $this->outsider] as $user) {
            $this->actingAs($user)->getJson($this->url('finance-badges'))->assertForbidden();
        }
    }

    public function test_queue_tabs_and_who_may_verify(): void
    {
        $verifyMe = $this->expenseIn('endorsed', null, true, ['pl_reviewed_by' => $this->projectLeader->id, 'pl_reviewed_at' => now()]);
        $rejectMe = $this->expenseIn('endorsed', null, true, ['pl_reviewed_by' => $this->projectLeader->id, 'pl_reviewed_at' => now()]);
        $mine = $this->expenseIn('endorsed', $this->finance, true, ['pl_reviewed_by' => $this->projectLeader->id, 'pl_reviewed_at' => now()]);

        $this->actingAs($this->finance);
        $item = $this->getJson($this->url("verification-queue/expense/{$mine->id}"))->assertOk();
        $item->assertJsonPath('item.can_verify', false)
            ->assertJsonPath('item.blocked_text', 'You recorded this expense; another Finance/Admin must verify it.');

        $this->postJson("/api/expenses/{$verifyMe->id}/verify")->assertOk();
        $this->postJson("/api/expenses/{$rejectMe->id}/reject", ['reason' => 'OR does not match.'])->assertOk();

        $queue = $this->getJson($this->url('verification-queue?tab=verified'))->assertOk();
        $queue->assertJsonPath('counts.waiting', 1)->assertJsonPath('counts.verified', 1)->assertJsonPath('counts.rejected', 1);
        $this->assertSame([$verifyMe->id], collect($queue->json('items'))->pluck('id')->all());
        $this->assertSame([$rejectMe->id], collect($this->getJson($this->url('verification-queue?tab=rejected'))->json('items'))->pluck('id')->all());
        $this->getJson($this->url('verification-queue?type=payroll'))->assertOk()->assertJsonPath('counts.waiting', 0);
    }

    public function test_a_batch_the_verifier_is_paid_in_has_no_verify_button(): void
    {
        // The only Finance/Admin left on the project is paid in the batch.
        AuditLogger::muted(fn () => ProjectUser::where('project_id', $this->project->id)->where('user_id', $this->finance2->id)->delete());
        $this->rate($this->finance, 25000);
        $batch = $this->submittedBatch();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();

        $item = $this->actingAs($this->finance)->getJson($this->url("verification-queue/payroll/{$batch->id}"))->assertOk()->json('item');
        $this->assertFalse($item['can_verify']);
        $this->assertNotNull($item['blocked_text']);
        $this->postJson("/api/payroll-batches/{$batch->id}/verify")->assertForbidden();
    }

    public function test_a_paid_finance_user_sees_the_paid_message_when_another_can_verify(): void
    {
        $this->rate($this->finance, 25000);
        $batch = $this->submittedBatch();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();

        $this->actingAs($this->finance)->getJson($this->url("verification-queue/payroll/{$batch->id}"))->assertOk()
            ->assertJsonPath('item.can_verify', false)
            ->assertJsonPath('item.blocked_text', FinanceService::PAID_IN_BATCH);
        $this->actingAs($this->finance2)->getJson($this->url("verification-queue/payroll/{$batch->id}"))->assertOk()
            ->assertJsonPath('item.can_verify', true)
            ->assertJsonPath('item.blocked_text', null);
    }

    // ---------------------------------------------------------------- receipts

    public function test_missing_receipts_are_equal_for_leader_assistant_and_finance(): void
    {
        $this->expenseIn('verified', null, false);
        $this->expenseIn('verified', null, false);
        $this->expenseIn('verified');
        $this->expenseIn('submitted', null, false); // not verified: not missing

        $pl = $this->actingAs($this->projectLeader)->getJson($this->url('leader-overview'))->json('alerts.missing_receipts.count');
        $ra = $this->actingAs($this->assistant)->getJson($this->url('assistant-overview'))->json('kpis.missing_receipts');
        $fin = $this->actingAs($this->finance)->getJson($this->url('finance-overview'))->json('kpis.missing_receipts');

        $this->assertSame(2, $pl);
        $this->assertSame([2, 2], [$ra, $fin]);
    }

    public function test_notify_ra_sends_one_notification_each_and_one_audit_entry(): void
    {
        $expense = $this->expenseIn('verified', null, false);
        $other = AuditLogger::muted(fn () => $this->member('research-assistant'));
        $audit = AuditTrail::count();

        $this->actingAs($this->finance)->postJson("/api/expenses/{$expense->id}/request-receipt")
            ->assertOk()->assertJsonPath('notified', 2);
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame('receipt_requested', AuditTrail::latest('id')->value('action'));
        foreach ([$this->assistant, $other] as $user) {
            $note = $user->fresh()->notifications->first();
            $this->assertSame('receipt_requested', $note->data['event']);
            $this->assertSame($expense->id, $note->data['expense_id']);
        }

        // Only for a verified expense still missing its receipt, only by Finance/Admin.
        $this->postJson("/api/expenses/{$this->expenseIn('verified')->id}/request-receipt")->assertStatus(422);
        foreach ([$this->assistant, $this->projectLeader, $this->researcher] as $user) {
            $this->actingAs($user)->postJson("/api/expenses/{$expense->id}/request-receipt")->assertForbidden();
        }
    }

    public function test_a_late_receipt_is_listed_as_information_not_to_verify(): void
    {
        $expense = $this->expenseIn('verified', null, false, ['verified_by' => $this->finance->id, 'verified_at' => now()->subDay()]);
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$expense->id}/documents", [
            'document_type' => 'receipt', 'file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $queue = $this->actingAs($this->finance)->getJson($this->url('verification-queue'))->assertOk();
        $queue->assertJsonPath('counts.waiting', 0);
        $this->assertSame([$expense->id], collect($queue->json('late_receipts'))->pluck('expense_id')->all());
        $this->assertSame('verified', $expense->fresh()->workflow_status);
    }

    // ---------------------------------------------------------------- consistency

    public function test_every_role_sees_the_same_budget_numbers(): void
    {
        $this->expenseIn('verified', null, true, ['amount' => 1250.5, 'expense_date' => now()->toDateString()]);
        $this->expenseIn('verified', null, true, ['lib_category_id' => $this->ps->id, 'amount' => 3000, 'expense_date' => now()->toDateString()]);
        $this->expenseIn('endorsed', null, true, ['amount' => 999]); // not spent

        $pl = $this->actingAs($this->projectLeader)->getJson($this->url('leader-overview'))->assertOk();
        $res = $this->actingAs($this->researcher)->getJson($this->url('budget-summary'))->assertOk();
        $fin = $this->actingAs($this->finance)->getJson($this->url('budget-summary'))->assertOk();
        $finOverview = $this->getJson($this->url('finance-overview'))->assertOk();
        $prg = $this->actingAs($this->programLeader)->getJson("/api/program/projects/{$this->project->id}")->assertOk();

        foreach ([$res, $fin] as $summary) {
            $this->assertEquals($pl->json('kpis.total_budget'), $summary->json('kpis.total_budget'));
            $this->assertEquals($pl->json('kpis.total_spent'), $summary->json('kpis.total_spent'));
            $this->assertEquals($pl->json('kpis.remaining'), $summary->json('kpis.remaining'));
            $this->assertEquals($pl->json('monthly_expenses'), $summary->json('monthly_expenses'));
            $this->assertEquals($pl->json('category_summary'), $summary->json('category_summary'));
        }
        $this->assertEquals(4250.5, $pl->json('kpis.total_spent'));
        $this->assertEquals($pl->json('kpis.total_spent'), $finOverview->json('kpis.total_spent'));
        $this->assertSame(2, $res->json('kpis.verified_transactions'));

        // Program Leader project page.
        $this->assertEquals($pl->json('kpis.total_budget'), $prg->json('project.budget'));
        $this->assertEquals($pl->json('kpis.total_spent'), $prg->json('project.spent'));
        $this->assertEquals($pl->json('kpis.remaining'), $prg->json('project.remaining'));
        $groups = collect($prg->json('line_items.groups'))->keyBy('type');
        $this->assertEquals(3000, $res->json('category_summary.ps.spent'));
        $this->assertEquals($res->json('category_summary.ps.spent'), $groups['PS']['subtotal']['spent']);
        $this->assertEquals($res->json('category_summary.mooe.spent'), $groups['MOOE']['subtotal']['spent']);
        $this->assertEquals($res->json('category_summary.ps.allocated'), $groups['PS']['subtotal']['allocated']);
    }

    public function test_verifying_an_expense_changes_spent_for_every_role_but_payroll_does_not(): void
    {
        $expense = $this->expenseIn('endorsed', null, true, ['amount' => 2000, 'pl_reviewed_by' => $this->projectLeader->id, 'pl_reviewed_at' => now()]);
        $batch = $this->submittedBatch();
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();

        $spent = fn () => [
            $this->actingAs($this->projectLeader)->getJson($this->url('leader-overview'))->json('kpis.total_spent'),
            $this->actingAs($this->finance)->getJson($this->url('finance-overview'))->json('kpis.total_spent'),
            $this->actingAs($this->researcher)->getJson($this->url('budget-summary'))->json('kpis.total_spent'),
            $this->actingAs($this->programLeader)->getJson("/api/program/projects/{$this->project->id}")->json('project.spent'),
        ];
        $this->assertEquals([0, 0, 0, 0], $spent());

        $this->actingAs($this->finance)->postJson("/api/expenses/{$expense->id}/verify")->assertOk();
        $this->assertEquals([2000, 2000, 2000, 2000], $spent());

        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk();
        $this->assertSame('verified', $batch->fresh()->workflow_status);
        $this->assertEquals([2000, 2000, 2000, 2000], $spent());

        // Verified this month: the amount is expenses only; the counts stay separate.
        $kpis = $this->actingAs($this->finance)->getJson($this->url('finance-overview'))->json('kpis');
        $this->assertEquals(2000, $kpis['verified_this_month']);
        $this->assertSame(1, $kpis['verified_this_month_expenses']);
        $this->assertSame(1, $kpis['verified_this_month_payroll']);
        $this->assertArrayNotHasKey('verified_this_month_count', $kpis);
    }

    public function test_the_researcher_cannot_call_any_write_endpoint(): void
    {
        $expense = $this->expenseIn('draft', null, true);
        $endorsed = $this->expenseIn('endorsed', null, true, ['pl_reviewed_by' => $this->projectLeader->id]);
        $verifiedNoReceipt = $this->expenseIn('verified', null, false);
        $request = AuditLogger::muted(fn () => PurchaseRequest::create([
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
            'description' => 'GPS units', 'quantity' => 1, 'estimated_amount' => 1000, 'workflow_status' => 'submitted',
        ]));
        $batch = $this->submittedBatch();
        $payrollRow = $batch->rows()->first();
        $document = $expense->documents()->first();
        $file = fn () => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');
        $p = $this->project->id;

        $calls = [
            ['post', "/api/projects/{$p}/expenses", $this->expensePayload()],
            ['patch', "/api/expenses/{$expense->id}", ['description' => 'Changed']],
            ['delete', "/api/expenses/{$expense->id}", []],
            ['post', "/api/expenses/{$expense->id}/submit", []],
            ['post', "/api/expenses/{$expense->id}/endorse", []],
            ['post', "/api/expenses/{$expense->id}/return", ['reason' => 'x']],
            ['post', "/api/expenses/{$endorsed->id}/verify", []],
            ['post', "/api/expenses/{$endorsed->id}/reject", ['reason' => 'x']],
            ['post', "/api/expenses/{$verifiedNoReceipt->id}/request-receipt", []],
            ['post', "/api/expenses/{$expense->id}/documents", ['document_type' => 'receipt', 'file' => $file()]],
            ['delete', "/api/documents/{$document->id}", []],
            ['post', "/api/projects/{$p}/payroll", ['month' => '2026-12', 'lib_category_id' => $this->ps->id]],
            ['post', "/api/projects/{$p}/payroll/compute", ['month' => '2026-11']],
            ['patch', "/api/payroll/{$payrollRow->id}", ['allowance' => 1]],
            ['post', "/api/payroll-batches/{$batch->id}/endorse", []],
            ['post', "/api/payroll-batches/{$batch->id}/return", ['reason' => 'x']],
            ['post', "/api/payroll-batches/{$batch->id}/verify", []],
            ['post', "/api/payroll-batches/{$batch->id}/documents", ['document_type' => 'payroll_file', 'file' => $file()]],
            ['patch', "/api/projects/{$p}/pay-rates/{$this->assistant->id}", ['monthly_rate' => 1]],
            ['post', "/api/projects/{$p}/purchase-requests", ['lib_category_id' => $this->lineItem->id, 'description' => 'x', 'quantity' => 1, 'estimated_amount' => 1]],
            ['patch', "/api/purchase-requests/{$request->id}", ['description' => 'x']],
            ['post', "/api/purchase-requests/{$request->id}/endorse", []],
            ['post', "/api/purchase-requests/{$request->id}/return", ['reason' => 'x']],
            ['post', "/api/purchase-requests/{$request->id}/cancel", ['reason' => 'x']],
            ['post', "/api/purchase-requests/{$request->id}/documents", ['document_type' => 'quotation', 'file' => $file()]],
            ['post', "/api/projects/{$p}/budget-requests", ['type' => 'realignment', 'justification' => 'x', 'from_lib_category_id' => $this->lineItem->id, 'to_lib_category_id' => $this->ps->id, 'amount' => 1]],
        ];

        $this->actingAs($this->researcher);
        $failures = [];
        foreach ($calls as [$method, $uri, $body]) {
            $status = $this->json(strtoupper($method), $uri, $body)->getStatusCode();
            if ($status !== 403) {
                $failures[] = strtoupper($method) . " {$uri} -> {$status}";
            }
        }
        $this->assertSame([], $failures);

        // Reads stay open.
        $this->getJson("/api/projects/{$p}/budget-summary")->assertOk();
        $this->getJson("/api/projects/{$p}/transactions")->assertOk();
        $this->getJson("/api/expenses/{$expense->id}/documents")->assertOk();
    }

    // ---------------------------------------------------------------- helpers

    private function submittedExpense(): int
    {
        $id = $this->actingAs($this->assistant)->postJson($this->url('expenses'), $this->expensePayload())->assertCreated()->json('expense.id');
        $this->postJson("/api/expenses/{$id}/documents", [
            'document_type' => 'receipt', 'file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'),
        ])->assertCreated();
        $this->postJson("/api/expenses/{$id}/submit")->assertOk();

        return $id;
    }

    private function rate(User $user, float $monthly): void
    {
        AuditLogger::muted(fn () => ProjectUser::where('project_id', $this->project->id)->where('user_id', $user->id)
            ->update(['monthly_rate' => $monthly, 'allowance' => 0]));
    }

    private function submittedBatch(): PayrollBatch
    {
        $this->rate($this->researcher, 30000);
        $this->actingAs($this->assistant)
            ->postJson($this->url('payroll'), ['month' => '2026-11', 'lib_category_id' => $this->ps->id])
            ->assertCreated();
        $batch = PayrollBatch::where('project_id', $this->project->id)->firstOrFail();
        $this->payrollFileFor($batch, $this->assistant);
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();

        return $batch->fresh();
    }
}

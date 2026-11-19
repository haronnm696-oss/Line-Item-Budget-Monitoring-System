<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\PayrollBatch;
use App\Models\ProjectUser;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Project Leader pages: Overview, Submissions (review queue), Transactions
 * and the notification targets. Decisions go through the existing workflow
 * endpoints, exactly as the UI calls them.
 */
class ProjectLeaderPagesTest extends TestCase
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

    public function test_the_to_review_count_equals_the_waiting_for_you_tab(): void
    {
        $this->expenseIn('submitted');
        $this->expenseIn('submitted');
        $this->purchaseRequestIn('submitted');
        $this->selfPayBatch(); // waits for the Program Leader, still listed
        $this->expenseIn('returned', null, true, ['return_reason' => 'Wrong OR number.']);
        $this->expenseIn('draft');
        $this->expenseIn('verified');

        $overview = $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/leader-overview")
            ->assertOk();
        $waiting = $this->getJson("/api/projects/{$this->project->id}/review-queue?tab=waiting")->assertOk();

        $this->assertSame(4, $overview->json('kpis.to_review'));
        $this->assertSame(3, $overview->json('kpis.to_review_actionable'));
        $this->assertCount($overview->json('kpis.to_review'), $waiting->json('items'));
        $this->assertSame($overview->json('kpis.to_review'), $waiting->json('counts.waiting'));
        $this->assertSame(1, $waiting->json('counts.returned'));

        // Tab counts follow the type filter.
        $this->getJson("/api/projects/{$this->project->id}/review-queue?tab=waiting&type=expense")
            ->assertJsonCount(2, 'items')->assertJsonPath('counts.waiting', 2)->assertJsonPath('counts.returned', 1);
        $this->getJson("/api/projects/{$this->project->id}/review-queue?tab=waiting&type=payroll")
            ->assertJsonCount(1, 'items')->assertJsonPath('counts.returned', 0);

        // Only this project's Project Leader may open the queue and the overview.
        foreach ([$this->assistant, $this->finance, $this->outsider] as $user) {
            $this->actingAs($user)->getJson("/api/projects/{$this->project->id}/review-queue")->assertForbidden();
            $this->actingAs($user)->getJson("/api/projects/{$this->project->id}/leader-overview")->assertForbidden();
        }
    }

    public function test_approve_and_return_change_the_record_and_notify_the_next_person(): void
    {
        $expense = $this->expenseIn('submitted');
        $request = $this->purchaseRequestIn('submitted');
        $this->actingAs($this->projectLeader);

        // Approve an expense: Finance/Admin is told it is ready to verify.
        $this->postJson("/api/expenses/{$expense->id}/endorse")->assertOk()->assertJsonPath('expense.workflow_status', 'endorsed');
        $this->assertSame(1, $this->finance->notifications()->get()->where('data.kind', 'expense')->where('data.event', 'endorsed')->count());

        // Return a purchase request: a reason is required; the requester is told.
        $this->postJson("/api/purchase-requests/{$request->id}/return", ['reason' => ''])->assertStatus(422);
        $this->postJson("/api/purchase-requests/{$request->id}/return", ['reason' => 'Attach a second quotation.'])
            ->assertOk()->assertJsonPath('purchase_request.workflow_status', 'returned');
        $note = $this->assistant->notifications()->get()->where('data.kind', 'purchase_request')->where('data.event', 'returned')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Attach a second quotation.', $note->data['message']);

        // The queue moves them: nothing waiting, one in each other tab.
        $queue = fn (string $tab) => $this->getJson("/api/projects/{$this->project->id}/review-queue?tab={$tab}")->json();
        $this->assertSame([], $queue('waiting')['items']);
        $this->assertSame(["expense-{$expense->id}"], array_column($queue('approved')['items'], 'key'));
        $returned = $queue('returned')['items'];
        $this->assertSame(["purchase_request-{$request->id}"], array_column($returned, 'key'));
        $this->assertSame('Attach a second quotation.', $returned[0]['return_reason']);

        // The side panel loads one record at any stage.
        $this->getJson("/api/projects/{$this->project->id}/review-queue/expense/{$expense->id}")
            ->assertOk()->assertJsonPath('item.workflow_status', 'endorsed')->assertJsonPath('item.can_review', false);
        $this->getJson("/api/projects/{$this->project->id}/review-queue/expense/999999")->assertNotFound();
    }

    public function test_a_payroll_batch_is_approved_from_the_queue_and_finance_is_told(): void
    {
        $this->rate($this->researcher, 30000);
        $batch = $this->submittedBatch();

        $item = $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/review-queue?tab=waiting&type=payroll")
            ->assertOk()->json('items.0');
        $this->assertSame([true, null, 'Payroll November 2026'], [$item['can_review'], $item['waiting_text'], $item['title']]);

        $this->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();
        $this->assertSame('endorsed', $batch->fresh()->workflow_status);
        $this->assertSame(1, $this->finance->notifications()->get()->where('data.kind', 'payroll')->where('data.event', 'endorsed')->count());
    }

    public function test_a_self_pay_batch_cannot_be_approved_by_the_paid_project_leader(): void
    {
        $batch = $this->selfPayBatch();

        $item = $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/review-queue?type=payroll")
            ->assertOk()->json('items.0');
        $this->assertFalse($item['can_review']);
        $this->assertSame('Waiting for Program Leader (includes your pay)', $item['waiting_text']);

        $this->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertForbidden();
        $this->postJson("/api/payroll-batches/{$batch->id}/return", ['reason' => 'x'])->assertForbidden();
        $this->assertSame('submitted', $batch->fresh()->workflow_status);

        // Even with a second Project Leader (older data), a batch that pays the
        // Project Leader goes to the Program Leader, never "another Project Leader".
        $second = AuditLogger::muted(fn () => $this->member('project-leader'));
        $this->rate($this->researcher, 30000);
        $this->rate($this->projectLeader, 45000);
        $other = $this->submittedBatch('2026-10');
        $this->assertNotNull($other->fresh()->pendingEscalation());
        $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/review-queue/payroll/{$other->id}")
            ->assertJsonPath('item.can_review', false)
            ->assertJsonPath('item.waiting_text', 'Waiting for Program Leader (includes your pay)');
        $this->actingAs($second)
            ->getJson("/api/projects/{$this->project->id}/review-queue/payroll/{$other->id}")
            ->assertJsonPath('item.can_review', false);
        $this->actingAs($second)->postJson("/api/payroll-batches/{$other->id}/endorse")->assertForbidden();
    }

    public function test_a_realignment_above_the_available_amount_is_refused(): void
    {
        $to = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'MOOE', 'line_item_name' => 'Travel', 'allocated_amount' => 1000,
        ]));
        $this->expenseIn('verified', null, true, ['amount' => 100000]);
        $available = $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/balancing?line_item={$this->lineItem->id}")
            ->json('line_items.0.available');
        $this->assertSame(400000.0, (float) $available);

        $payload = [
            'type' => 'realignment', 'from_lib_category_id' => $this->lineItem->id, 'to_lib_category_id' => $to->id,
            'justification' => 'Move savings to field travel.',
        ];
        $this->postJson("/api/projects/{$this->project->id}/budget-requests", $payload + ['amount' => 400000.01])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/projects/{$this->project->id}/budget-requests", $payload + ['amount' => 400000])
            ->assertCreated()->assertJsonPath('budget_request.status', 'pending');
    }

    public function test_transactions_are_verified_expenses_newest_first_25_per_page_with_filters(): void
    {
        foreach (range(1, 27) as $day) {
            $this->expenseIn('verified', null, true, ['expense_date' => sprintf('2026-10-%02d', $day), 'amount' => 100]);
        }
        $this->expenseIn('submitted', null, true, ['expense_date' => '2026-10-28']);
        $this->expenseIn('verified', null, true, ['expense_date' => '2026-10-28', 'lib_category_id' => $this->ps->id, 'amount' => 500]);

        $first = $this->actingAs($this->researcher)->getJson("/api/projects/{$this->project->id}/transactions")->assertOk();
        $this->assertCount(25, $first->json('transactions'));
        $this->assertEquals(['page' => 1, 'per_page' => 25, 'total' => 28, 'last_page' => 2, 'amount' => 3200], $first->json('meta'));
        $this->assertSame('2026-10-28', $first->json('transactions.0.date'));
        $this->assertCount(3, $this->getJson("/api/projects/{$this->project->id}/transactions?page=2")->json('transactions'));

        $this->getJson("/api/projects/{$this->project->id}/transactions?category=PS")->assertJsonPath('meta.total', 1);
        $this->getJson("/api/projects/{$this->project->id}/transactions?line_item={$this->lineItem->id}&from=2026-10-20&to=2026-10-27")
            ->assertJsonPath('meta.total', 8);
        $this->getJson("/api/projects/{$this->project->id}/transactions?from=2026-10-20&to=2026-10-01")->assertStatus(422);
        $this->actingAs($this->outsider)->getJson("/api/projects/{$this->project->id}/transactions")->assertForbidden();
    }

    public function test_a_notification_names_the_record_it_is_about(): void
    {
        $expense = $this->expenseIn('draft');
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$expense->id}/submit")->assertOk();

        $this->actingAs($this->projectLeader)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.record', ['type' => 'expense', 'id' => $expense->id])
            ->assertJsonPath('notifications.0.project_id', $this->project->id);
    }

    // ------------------------------------------------------------ Project Leader records an expense

    public function test_the_project_leader_records_and_submits_an_expense_that_skips_their_approval(): void
    {
        $this->actingAs($this->projectLeader);
        $id = $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload())
            ->assertCreated()->assertJsonPath('expense.workflow_status', 'draft')->json('expense.id');

        // Same receipt rule as the Research Assistant.
        $this->postJson("/api/expenses/{$id}/submit")->assertStatus(422)->assertJsonValidationErrors('documents');
        $this->post("/api/expenses/{$id}/documents", [
            'file' => UploadedFile::fake()->create('or.pdf', 20, 'application/pdf'), 'document_type' => 'receipt',
        ], ['Accept' => 'application/json'])->assertCreated();

        $audit = AuditTrail::count();
        $this->postJson("/api/expenses/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'endorsed')
            ->assertJsonPath('expense.workflow_label', 'Waiting for Finance/Admin verification')
            ->assertJsonPath('expense.pl_reviewed_by', null);
        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->firstOrFail();
        $this->assertSame(['submitted', $id], [$entry->action, (int) $entry->model_id]);

        // Finance/Admin is told; nobody is asked for a Project Leader approval.
        foreach ([$this->finance, $this->finance2] as $user) {
            $this->assertSame(1, $user->notifications()->get()->where('data.kind', 'expense')->where('data.event', 'leader_submitted')->count());
        }
        $this->assertSame(0, $this->projectLeader->notifications()->count());
        $this->getJson("/api/projects/{$this->project->id}/review-queue")->assertJsonPath('counts.waiting', 0);
        $this->postJson("/api/expenses/{$id}/endorse")->assertForbidden(); // the recorder never approves

        // The recorder never verifies; Finance/Admin does, and it counts as spent.
        $this->postJson("/api/expenses/{$id}/verify")->assertForbidden();
        $this->actingAs($this->finance)->postJson("/api/expenses/{$id}/verify")
            ->assertOk()->assertJsonPath('expense.workflow_status', 'verified');
        $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/transactions")
            ->assertJsonPath('meta.total', 1)->assertJsonPath('transactions.0.id', $id);
    }

    public function test_the_project_leader_edits_and_resubmits_an_expense_finance_rejected(): void
    {
        $receipt = fn (string $name) => ['file' => UploadedFile::fake()->create($name, 20, 'application/pdf'), 'document_type' => 'receipt'];
        $json = ['Accept' => 'application/json'];

        // Recorded and submitted by the Project Leader; Finance/Admin rejects it.
        $this->actingAs($this->projectLeader);
        $id = $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['amount' => 2500]))->json('expense.id');
        $oldReceipt = $this->post("/api/expenses/{$id}/documents", $receipt('blurry.pdf'), $json)->assertCreated()->json('document.id');
        $this->postJson("/api/expenses/{$id}/submit")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/expenses/{$id}/reject", ['reason' => 'The OR is unreadable and the amount is ₱2,450.'])
            ->assertOk()->assertJsonPath('expense.workflow_status', 'returned');
        $this->assertSame(1, $this->projectLeader->notifications()->get()->where('data.event', 'rejected')->count());

        // The side panel offers "Edit and resubmit" with the reason and the stored values.
        $item = $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/review-queue/expense/{$id}")->assertOk()->json('item');
        $this->assertTrue($item['can_edit']);
        $this->assertSame('The OR is unreadable and the amount is ₱2,450.', $item['return_reason']);
        $this->assertEquals(['lib_category_id' => $this->lineItem->id, 'funding_source' => 'funder', 'description' => 'Laboratory reagents', 'amount' => 2500,
            'expense_date' => '2026-11-10', 'payee' => 'Iligan Lab Supply', 'reference_no' => 'OR-1001'], $item['edit']);

        // Correct it and replace the receipt (each change has its own audit entry, as for any edit).
        $this->patchJson("/api/expenses/{$id}", ['amount' => 2450])->assertOk();
        $this->deleteJson("/api/documents/{$oldReceipt}")->assertOk();
        $this->post("/api/expenses/{$id}/documents", $receipt('clear.pdf'), $json)->assertCreated();

        // Resubmit: one audit entry, one notification per Finance/Admin, straight to verification.
        $audit = AuditTrail::count();
        $before = fn (User $u) => $u->notifications()->get()->where('data.event', 'leader_submitted')->count();
        $counts = [$before($this->finance), $before($this->finance2)];
        $this->postJson("/api/expenses/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'endorsed')
            ->assertJsonPath('expense.workflow_label', 'Waiting for Finance/Admin verification')
            ->assertJsonPath('expense.amount', 2450)
            ->assertJsonPath('expense.return_reason', null);
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame('submitted', AuditTrail::latest('id')->value('action'));
        $this->assertSame([$counts[0] + 1, $counts[1] + 1], [$before($this->finance), $before($this->finance2)]);
        $this->assertSame(['clear.pdf'], \App\Models\Expense::findOrFail($id)->documents()->pluck('file_name')->all());

        $this->actingAs($this->finance2)->postJson("/api/expenses/{$id}/verify")->assertOk()->assertJsonPath('expense.workflow_status', 'verified');
    }

    public function test_an_expense_someone_else_recorded_stays_read_only_for_the_project_leader(): void
    {
        $returned = $this->expenseIn('returned', null, true, ['return_reason' => 'Wrong line item.']);

        $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/review-queue/expense/{$returned->id}")
            ->assertJsonPath('item.can_edit', false)->assertJsonPath('item.edit', null);
        $this->patchJson("/api/expenses/{$returned->id}", ['amount' => 1])->assertForbidden();
        $this->postJson("/api/expenses/{$returned->id}/submit")->assertForbidden();
    }

    public function test_a_project_leader_acts_only_on_expenses_they_recorded(): void
    {
        $draft = $this->expenseIn('draft', null, false);
        $this->actingAs($this->projectLeader);

        $this->patchJson("/api/expenses/{$draft->id}", ['description' => 'Changed'])
            ->assertForbidden()->assertJsonPath('message', 'A Project Leader can only edit expenses they recorded.');
        $this->postJson("/api/expenses/{$draft->id}/submit")->assertForbidden();
        $this->deleteJson("/api/expenses/{$draft->id}")->assertForbidden();
        $this->post("/api/expenses/{$draft->id}/documents", [
            'file' => UploadedFile::fake()->create('or.pdf', 20, 'application/pdf'), 'document_type' => 'receipt',
        ], ['Accept' => 'application/json'])->assertForbidden();

        // A researcher still cannot record.
        $this->actingAs($this->researcher)->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload())->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->workflow_status);
    }

    // ------------------------------------------------------------ sidebar badges

    public function test_the_badges_match_the_to_review_count_and_unseen_decisions(): void
    {
        $this->expenseIn('submitted');
        $this->purchaseRequestIn('submitted');
        $this->actingAs($this->projectLeader);

        $toReview = $this->getJson("/api/projects/{$this->project->id}/leader-overview")->json('kpis.to_review');
        $this->getJson("/api/projects/{$this->project->id}/leader-badges")
            ->assertOk()->assertJsonPath('submissions', $toReview)->assertJsonPath('requests', 0);
        $this->assertSame(2, $toReview);

        // Two requests decided by the Program Leader: 2 unseen until My requests is opened.
        $ids = [];
        foreach (['Supplies top-up', 'Field travel'] as $title) {
            $ids[] = $this->postJson("/api/projects/{$this->project->id}/budget-requests", [
                'type' => 'liquidation', 'title' => $title, 'amount' => 1000, 'justification' => 'Liquidate the first tranche.',
                'files' => [UploadedFile::fake()->create('l.pdf', 20, 'application/pdf')],
            ], ['Accept' => 'application/json'])->assertCreated()->json('budget_request.id');
        }
        $this->actingAs($this->programLeader)->postJson("/api/program/approvals/{$ids[0]}/decision", ['decision' => 'approved'])->assertOk();
        $this->postJson("/api/program/approvals/{$ids[1]}/decision", ['decision' => 'rejected', 'remarks' => 'Missing OR.'])->assertOk();

        $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/leader-badges")->assertJsonPath('requests', 2);
        $this->postJson("/api/projects/{$this->project->id}/budget-requests/seen")->assertOk()->assertJsonPath('requests', 0);
        $this->getJson("/api/projects/{$this->project->id}/leader-badges")->assertJsonPath('requests', 0);

        $this->actingAs($this->assistant)->getJson("/api/projects/{$this->project->id}/leader-badges")->assertForbidden();
    }

    // ------------------------------------------------------------ helpers

    private function purchaseRequestIn(string $stage): PurchaseRequest
    {
        return AuditLogger::muted(fn () => PurchaseRequest::create([
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
            'description' => 'Microscope slides', 'quantity' => 10, 'estimated_amount' => 3500,
            'workflow_status' => $stage, 'submitted_at' => now(),
        ]));
    }

    private function rate(User $user, float $monthly): void
    {
        $this->setHonorarium($user, $monthly);
    }

    /** A month's payroll created and submitted by the Research Assistant. */
    private function submittedBatch(string $month = '2026-11'): PayrollBatch
    {
        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $month, 'lib_category_id' => $this->ps->id])
            ->assertCreated();
        [$year, $number] = array_map('intval', explode('-', $month));
        $batch = PayrollBatch::where('project_id', $this->project->id)->where('pay_year', $year)->where('pay_month', $number)->firstOrFail();
        $this->payrollFileFor($batch, $this->assistant);
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();

        return $batch->fresh();
    }

    /** A submitted batch that pays the only Project Leader (escalated to the Program Leader). */
    private function selfPayBatch(): PayrollBatch
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->projectLeader, 45000);

        return $this->submittedBatch();
    }
}

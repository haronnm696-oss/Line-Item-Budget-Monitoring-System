<?php

namespace Tests\Feature;

use App\Support\Percent;
use App\Http\Controllers\DocumentController;
use App\Models\AuditTrail;
use App\Models\Expense;
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
 * Research Assistant pages: Overview KPIs, My submissions, the sidebar
 * badge, edit-and-resubmit after a return, and linking an expense to a
 * purchase request. Every step goes through the workflow endpoints the UI
 * calls.
 */
class ResearchAssistantPagesTest extends TestCase
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

    private function url(string $path = ''): string
    {
        return "/api/projects/{$this->project->id}/{$path}";
    }

    public function test_waiting_for_project_leader_equals_the_project_leaders_to_review(): void
    {
        // Built through the API, the way the Research Assistant encodes them.
        $this->recordAndSubmit();
        $this->recordAndSubmit(['description' => 'Printing of survey forms']);
        $this->purchaseRequestSubmitted();
        $this->submittedBatch();
        $this->recordDraft(); // a draft is not waiting
        $this->expenseIn('returned', null, true, ['return_reason' => 'Unclear receipt.']);

        $ra = $this->actingAs($this->assistant)->getJson($this->url('assistant-overview'))->assertOk();
        $pl = $this->actingAs($this->projectLeader)->getJson($this->url('leader-overview'))->assertOk();

        $this->assertSame(4, $ra->json('kpis.waiting'));
        $this->assertSame($pl->json('kpis.to_review'), $ra->json('kpis.waiting'));
        $this->assertSame(1, $ra->json('kpis.drafts'));
        $this->assertSame(1, $ra->json('kpis.returned'));

        $tab = $this->actingAs($this->assistant)->getJson($this->url('my-submissions?tab=waiting'))->assertOk();
        $this->assertCount(4, $tab->json('items'));
        $this->assertSame(4, $tab->json('counts.waiting'));
        $this->assertSame('Waiting for the Project Leader', collect($tab->json('items'))->firstWhere('type', 'expense')['next_step']);
    }

    public function test_the_sidebar_badge_equals_the_returned_kpi(): void
    {
        $this->expenseIn('returned', null, true, ['return_reason' => 'Wrong OR number.']);
        $this->expenseIn('returned', null, true, ['return_reason' => 'Blurred.']);
        AuditLogger::muted(fn () => PurchaseRequest::create([
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
            'description' => 'GPS units', 'quantity' => 2, 'estimated_amount' => 24000, 'workflow_status' => 'returned',
        ]));
        // Someone else's returned expense is not "returned to you".
        $other = $this->member('research-assistant');
        $this->expenseIn('returned', $other, true, ['return_reason' => 'Not mine.']);

        $overview = $this->actingAs($this->assistant)->getJson($this->url('assistant-overview'))->assertOk();
        $badges = $this->getJson($this->url('assistant-badges'))->assertOk();
        $returned = $this->getJson($this->url('my-submissions?tab=returned'))->assertOk();

        $this->assertSame(3, $overview->json('kpis.returned'));
        $this->assertSame($overview->json('kpis.returned'), $badges->json('my_submissions'));
        $this->assertSame($badges->json('my_submissions'), $returned->json('counts.returned'));

        // Only a Research Assistant of the project opens these pages.
        foreach ([$this->projectLeader, $this->finance, $this->outsider] as $user) {
            $this->actingAs($user)->getJson($this->url('assistant-badges'))->assertForbidden();
        }
    }

    public function test_submit_without_a_receipt_is_refused(): void
    {
        $id = $this->recordDraft();

        $this->actingAs($this->assistant)->postJson("/api/expenses/{$id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('errors.documents.0', 'Attach at least one receipt before submitting.');
        $this->assertSame('draft', Expense::find($id)->workflow_status);

        $item = $this->getJson($this->url("my-submissions/expense/{$id}"))->assertOk();
        $item->assertJsonPath('item.can_submit', false)->assertJsonPath('item.next_step', 'Attach the receipt, then submit');
    }

    public function test_edit_and_resubmit_after_a_project_leader_return(): void
    {
        $id = $this->recordAndSubmit();
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$id}/return", ['reason' => 'Receipt is unclear.'])->assertOk();

        $item = $this->actingAs($this->assistant)->getJson($this->url("my-submissions/expense/{$id}"))->assertOk();
        $item->assertJsonPath('item.workflow_status', 'returned')
            ->assertJsonPath('item.return_reason', 'Receipt is unclear.')
            ->assertJsonPath('item.returned_by.id', $this->projectLeader->id)
            ->assertJsonPath('item.can_edit', true);

        $this->resubmitWithNewReceipt($id);
        $this->assertSame('submitted', Expense::find($id)->workflow_status);
        $this->assertSame(1, Expense::find($id)->receipts()->count());
    }

    public function test_edit_and_resubmit_is_offered_on_returned_rows_for_the_maker_only(): void
    {
        $expenseId = $this->recordAndSubmit();
        $requestId = $this->purchaseRequestSubmitted();
        $batch = $this->submittedBatch();
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$expenseId}/return", ['reason' => 'Receipt is unclear.'])->assertOk();
        $this->postJson("/api/purchase-requests/{$requestId}/return", ['reason' => 'Attach a second quotation.'])->assertOk();
        $this->postJson("/api/payroll-batches/{$batch->id}/return", ['reason' => 'Check the allowance.'])->assertOk();

        // The maker's Returned tab (the list rows the button reads) flags every one as editable.
        $rows = collect($this->actingAs($this->assistant)->getJson($this->url('my-submissions?tab=returned'))->assertOk()->json('items'));
        $this->assertEqualsCanonicalizing(['expense', 'purchase_request', 'payroll'], $rows->pluck('type')->all());
        foreach ($rows as $row) {
            $this->assertSame('returned', $row['workflow_status'], $row['key']);
            $this->assertTrue($row['can_edit'], $row['key']);
        }
        // Expenses and receipts (status Returned) uses the same rows.
        $expenses = $this->getJson($this->url('my-submissions?tab=returned&type=expense'))->assertOk()->json('items');
        $this->assertCount(1, $expenses);
        $this->assertTrue($expenses[0]['can_edit']);

        // Another Research Assistant of the project: no row, and the record is not editable.
        $other = $this->member('research-assistant');
        $this->assertSame([], $this->actingAs($other)->getJson($this->url('my-submissions?tab=returned'))->assertOk()->json('items'));
        foreach (['expense' => $expenseId, 'purchase_request' => $requestId, 'payroll' => $batch->id] as $type => $id) {
            $this->getJson($this->url("my-submissions/{$type}/{$id}"))->assertOk()
                ->assertJsonPath('item.can_edit', false)
                ->assertJsonPath('item.edit', null);
        }
    }

    public function test_the_finance_expense_list_offers_resubmit_to_the_recorder_only(): void
    {
        $mine = $this->expenseIn('returned', $this->finance, true, ['return_reason' => 'Wrong OR number.']);
        $theirs = $this->expenseIn('returned', null, true, ['return_reason' => 'Blurred.']);
        $submitted = $this->expenseIn('submitted', $this->finance);

        $rows = collect($this->actingAs($this->finance)->getJson($this->url('expenses'))->assertOk()->json('expenses'))->keyBy('id');
        $this->assertTrue($rows[$mine->id]['can_resubmit']);
        $this->assertFalse($rows[$theirs->id]['can_resubmit']);
        $this->assertFalse($rows[$submitted->id]['can_resubmit']);

        $this->actingAs($this->projectLeader)->getJson($this->url('expenses'))->assertOk()
            ->assertJsonMissing(['can_resubmit' => true]);
    }

    public function test_the_payroll_rates_label_is_sent_only_when_set(): void
    {
        $this->submittedBatch();
        $urls = [
            [$this->assistant, $this->url('payroll?month=2026-11'), 'rates_label'],
            [$this->finance, $this->url('payroll-batches'), 'rates_label'],
            [$this->finance, $this->url(), 'payroll_rates_label'], // Reports: Payroll register
        ];

        config(['payroll.rates_label' => null]);
        foreach ($urls as [$user, $url, $key]) {
            $this->assertNull($this->actingAs($user)->getJson($url)->assertOk()->json($key), $url);
        }

        config(['payroll.rates_label' => 'Demo deduction rates, not official']);
        foreach ($urls as [$user, $url, $key]) {
            $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath($key, 'Demo deduction rates, not official');
        }
    }

    public function test_dashboards_keep_a_tiny_spent_share_above_zero(): void
    {
        // ₱300 verified of the project budget: rounds to 0.0%, must not read as nothing.
        $this->expenseIn('verified', null, true, ['amount' => 300]);

        $kpis = $this->actingAs($this->assistant)->getJson($this->url())->assertOk()->json('kpis');
        $this->assertGreaterThan(0, $kpis['utilization_pct']);
        $this->assertLessThan(0.05, $kpis['utilization_pct']);

        // The CSV keeps the plain number (spreadsheets sum and sort it), never "<0.1".
        $csv = $this->actingAs($this->projectLeader)->get($this->url('reports/utilization'))->assertOk()->getContent();
        $total = collect(preg_split('/
/', ltrim($csv, "ï»¿")))->first(fn ($line) => str_starts_with($line, 'GRAND TOTAL'));
        $cell = str_getcsv($total)[5];
        $this->assertIsNumeric($cell);
        $this->assertEqualsWithDelta($kpis['utilization_pct'], (float) $cell, 1e-6);
        $this->assertStringNotContainsString('<', $csv);
    }

    public function test_edit_and_resubmit_after_a_finance_rejection(): void
    {
        $id = $this->recordAndSubmit();
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$id}/endorse")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/expenses/{$id}/reject", ['reason' => 'Amount does not match the OR.'])->assertOk();

        $item = $this->actingAs($this->assistant)->getJson($this->url("my-submissions/expense/{$id}"))->assertOk();
        $item->assertJsonPath('item.returned_by.id', $this->finance->id)
            ->assertJsonPath('item.return_reason', 'Amount does not match the OR.');

        $this->actingAs($this->assistant)->patchJson("/api/expenses/{$id}", ['amount' => 2400])->assertOk();
        $this->resubmitWithNewReceipt($id);
        $expense = Expense::find($id);
        $this->assertSame('submitted', $expense->workflow_status);
        $this->assertEquals(2400, (float) $expense->amount);
        $this->assertSame(0, $this->actingAs($this->assistant)->getJson($this->url('assistant-badges'))->json('my_submissions'));
    }

    public function test_linking_an_expense_fulfils_the_purchase_request_without_double_counting(): void
    {
        $prId = $this->purchaseRequestSubmitted(['estimated_amount' => 24000]);
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$prId}/endorse")->assertOk();

        $before = $this->balancing();
        $this->assertEquals(24000, $before['pending']);
        $this->assertEquals(500000 - 24000, $before['available']);

        // Record the expense linked to the approved request, attach, submit.
        $id = $this->recordDraft(['amount' => 23500, 'purchase_request_id' => $prId]);
        $this->assertSame('fulfilled', PurchaseRequest::find($prId)->workflow_status);
        $linked = $this->balancing();
        $this->assertEquals(23500, $linked['pending']); // the expense replaces the request, not added to it
        $this->assertEquals(500000 - 23500, $linked['available']);

        $this->attachReceipt($id);
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$id}/endorse")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/expenses/{$id}/verify")->assertOk();

        $verified = $this->balancing();
        $this->assertEquals(23500, $verified['spent']);
        $this->assertEquals(0, $verified['pending']);
        $this->assertEquals(500000 - 23500, $verified['available']);
    }

    public function test_a_missing_receipt_can_be_added_to_a_verified_expense_once(): void
    {
        $expense = $this->expenseIn('verified', null, false);
        $overview = $this->actingAs($this->assistant)->getJson($this->url('assistant-overview'))->assertOk();
        $this->assertSame(1, $overview->json('kpis.missing_receipts'));

        $this->postJson("/api/expenses/{$expense->id}/documents", ['document_type' => 'invoice', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertStatus(422);
        $this->attachReceipt($expense->id);
        $this->assertSame(0, $this->getJson($this->url('assistant-overview'))->json('kpis.missing_receipts'));
        $this->assertSame('verified', $expense->fresh()->workflow_status);

        // Once the receipt is on file the verified expense is closed again.
        $this->postJson("/api/expenses/{$expense->id}/documents", ['document_type' => 'receipt', 'file' => UploadedFile::fake()->create('y.pdf', 10, 'application/pdf')])
            ->assertStatus(422);
    }

    public function test_a_late_receipt_writes_one_audit_entry_and_notifies_finance(): void
    {
        $expense = $this->expenseIn('verified', null, false);
        $audit = AuditTrail::count();

        $this->actingAs($this->assistant);
        $this->attachReceipt($expense->id);

        // Exactly one audit entry: the document, marked as a late receipt.
        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame('created', $entry->action);
        $this->assertSame('Document', $entry->model_type);
        $this->assertSame($this->assistant->id, (int) $entry->user_id);
        $this->assertSame((int) $this->project->id, (int) $entry->project_id);
        $this->assertSame(DocumentController::LATE_RECEIPT, $entry->reason);

        // Every Finance/Admin of the project, once each; nobody else.
        foreach ([$this->finance, $this->finance2] as $user) {
            $notes = $user->fresh()->notifications;
            $this->assertCount(1, $notes);
            $this->assertSame('late_receipt', $notes[0]->data['event']);
            $this->assertSame($expense->id, (int) $notes[0]->data['expense_id']);
        }
        foreach ([$this->assistant, $this->projectLeader, $this->outsider] as $user) {
            $this->assertCount(0, $user->fresh()->notifications);
        }

        // A receipt on a draft is an ordinary upload: no reason, no notification.
        $draft = $this->recordDraft();
        $this->attachReceipt($draft);
        $this->assertNull(AuditTrail::latest('id')->first()->reason);
        $this->assertCount(1, $this->finance->fresh()->notifications);
    }

    public function test_the_completed_tab_holds_every_finished_record_with_its_own_status(): void
    {
        $verified = $this->expenseIn('verified');
        $make = fn (string $stage) => AuditLogger::muted(fn () => PurchaseRequest::create([
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
            'description' => 'GPS units', 'quantity' => 1, 'estimated_amount' => 1000, 'workflow_status' => $stage,
        ]));
        $fulfilled = $make('fulfilled');
        $cancelled = $make('cancelled');
        $make('endorsed'); // approved, not finished
        $batch = AuditLogger::muted(fn () => PayrollBatch::create([
            'project_id' => $this->project->id, 'pay_year' => 2026, 'pay_month' => 10,
            'prepared_by' => $this->assistant->id, 'workflow_status' => 'verified',
        ]));

        $tab = $this->actingAs($this->assistant)->getJson($this->url('my-submissions?tab=completed'))->assertOk();
        $tab->assertJsonPath('counts.completed', 4)->assertJsonPath('counts.approved', 1);
        $this->assertArrayNotHasKey('verified', $tab->json('counts'));
        $status = collect($tab->json('items'))->mapWithKeys(fn ($item) => [$item['key'] => [$item['tab'], $item['workflow_status']]]);
        $this->assertEquals([
            "expense-{$verified->id}" => ['completed', 'verified'],
            "purchase_request-{$fulfilled->id}" => ['completed', 'fulfilled'],
            "purchase_request-{$cancelled->id}" => ['completed', 'cancelled'],
            "payroll-{$batch->id}" => ['completed', 'verified'],
        ], $status->all());

        $this->getJson($this->url('my-submissions?tab=verified'))->assertStatus(422);
    }

    public function test_documents_list_covers_the_projects_records_with_filters(): void
    {
        $id = $this->recordDraft();
        $this->attachReceipt($id);
        $prId = $this->purchaseRequestSubmitted();

        $all = $this->actingAs($this->assistant)->getJson($this->url('documents'))->assertOk();
        $this->assertCount(2, $all->json('documents'));
        $this->getJson($this->url('documents?record=purchase_request'))->assertOk()
            ->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.record.id', $prId);
        $this->getJson($this->url('documents?document_type=receipt'))->assertOk()
            ->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.record.type', 'expense');
        $this->actingAs($this->outsider)->getJson($this->url('documents'))->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    private function recordDraft(array $overrides = []): int
    {
        return $this->actingAs($this->assistant)
            ->postJson($this->url('expenses'), $this->expensePayload($overrides))
            ->assertCreated()
            ->json('expense.id');
    }

    private function attachReceipt(int $expenseId): void
    {
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$expenseId}/documents", [
            'document_type' => 'receipt',
            'file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
        ])->assertCreated();
    }

    private function recordAndSubmit(array $overrides = []): int
    {
        $id = $this->recordDraft($overrides);
        $this->attachReceipt($id);
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$id}/submit")->assertOk();

        return $id;
    }

    /** Remove the old receipt (own document) and add a new one, then submit. */
    private function resubmitWithNewReceipt(int $id): void
    {
        $old = $this->actingAs($this->assistant)->getJson("/api/expenses/{$id}/documents")->json('documents.0.id');
        $this->deleteJson("/api/documents/{$old}")->assertOk();
        $this->postJson("/api/expenses/{$id}/submit")->assertStatus(422); // no receipt left
        $this->attachReceipt($id);
        $this->postJson("/api/expenses/{$id}/submit")->assertOk();
    }

    private function purchaseRequestSubmitted(array $overrides = []): int
    {
        $id = $this->actingAs($this->assistant)->postJson($this->url('purchase-requests'), array_merge([
            'lib_category_id' => $this->lineItem->id, 'description' => 'GPS units', 'quantity' => 2, 'estimated_amount' => 24000,
        ], $overrides))->assertCreated()->json('purchase_request.id');
        $this->postJson("/api/purchase-requests/{$id}/documents", [
            'document_type' => 'quotation',
            'file' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'),
        ])->assertCreated();
        $this->postJson("/api/purchase-requests/{$id}/submit")->assertOk();

        return $id;
    }

    private function submittedBatch(): PayrollBatch
    {
        $this->setHonorarium($this->researcher, 30000);
        $this->actingAs($this->assistant)
            ->postJson($this->url('payroll'), ['month' => '2026-11', 'lib_category_id' => $this->ps->id])
            ->assertCreated();
        $batch = PayrollBatch::where('project_id', $this->project->id)->firstOrFail();
        $this->payrollFileFor($batch, $this->assistant);
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();

        return $batch->fresh();
    }

    private function balancing(): array
    {
        $row = collect($this->actingAs($this->assistant)->getJson($this->url('balancing'))->assertOk()->json('line_items'))
            ->firstWhere('id', $this->lineItem->id);

        return ['spent' => $row['spent'], 'pending' => $row['pending'], 'available' => $row['available']];
    }
}

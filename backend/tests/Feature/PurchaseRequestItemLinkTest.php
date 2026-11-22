<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * An expense pays ticked items of an approved purchase request (all on one
 * line item). Items are Open, Linked (pending) or Paid; the request is
 * Partly fulfilled, then Fulfilled. A linked item's money moves to the
 * expense (a lower amount releases the difference); cancelling releases
 * only open items; deleting a linked draft frees its items.
 */
class PurchaseRequestItemLinkTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private LibCategory $equipment;
    private LibCategory $travel;
    private PurchaseRequest $request;
    /** @var Collection<int, PurchaseRequestItem> microscope, centrifuge (Equipment), containers (Supplies), transport (Travel) */
    private Collection $items;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->buildWorkflowFixtures();
        AuditLogger::muted(function () {
            $this->equipment = LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'MOOE', 'line_item_name' => 'Laboratory equipment', 'allocated_amount' => 200000]);
            $this->travel = LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'MOOE', 'line_item_name' => 'Travel', 'allocated_amount' => 50000]);
        });

        $id = $this->actingAs($this->assistant)->postJson("/api/projects/{$this->project->id}/purchase-requests", [
            'purpose' => 'Laboratory set-up', 'supplier' => 'ABC Lab Supply',
            'items' => [
                ['lib_category_id' => $this->equipment->id, 'quantity' => 2, 'unit' => 'unit', 'description' => 'Digital microscope', 'unit_cost' => 40000],
                ['lib_category_id' => $this->equipment->id, 'quantity' => 1, 'unit' => 'unit', 'description' => 'Centrifuge', 'unit_cost' => 20000],
                ['lib_category_id' => $this->lineItem->id, 'quantity' => 2, 'unit' => 'box', 'description' => 'Specimen containers', 'unit_cost' => 6000],
                ['lib_category_id' => $this->travel->id, 'quantity' => 1, 'unit' => 'lot', 'description' => 'Transport of samples', 'unit_cost' => 13500],
            ],
        ])->assertCreated()->json('purchase_request.id');
        $this->request = PurchaseRequest::findOrFail($id);
        AuditLogger::muted(fn () => $this->request->documents()->create([
            'uploaded_by' => $this->assistant->id, 'document_type' => 'quotation', 'file_name' => 'q.pdf',
            'file_path' => 'documents/purchase-requests/q.pdf', 'disk' => 'local', 'uploaded_at' => now(),
        ]));
        $this->postJson("/api/purchase-requests/{$id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$id}/endorse")->assertOk();
        $this->items = $this->request->items()->get()->values();
    }

    public function test_partial_then_full_fulfilment_with_item_statuses(): void
    {
        [$microscope, $centrifuge, $containers, $transport] = $this->items->all();

        $first = $this->record($this->equipment, 80000, [$microscope->id], ['reference_no' => 'OR-2001'])
            ->assertCreated()->assertJsonPath('expense.purchase_request_item_ids', [$microscope->id])->json('expense.id');
        $row = $this->requestRow();
        $this->assertSame('endorsed', $row['workflow_status']);
        $this->assertSame('Partly fulfilled', $row['workflow_label']);
        $this->assertSame('partial', $row['fulfilment']);
        $this->assertSame(['linked', 'open', 'open', 'open'], array_column($row['items'], 'status'));
        $this->assertSame(['Linked (pending)', 'Open'], [$row['items'][0]['status_label'], $row['items'][1]['status_label']]);
        $this->assertSame('OR-2001', $row['items'][0]['expense_reference']);
        $this->assertSame('linked', AuditTrail::latest('id')->value('action'));

        $this->record($this->equipment, 20000, [$centrifuge->id])->assertCreated();
        $this->record($this->lineItem, 12000, [$containers->id])->assertCreated();
        $this->record($this->travel, 13500, [$transport->id])->assertCreated();

        // Every item has an expense: nothing left to link, not yet all paid.
        $row = $this->requestRow();
        $this->assertSame('fulfilled', $row['workflow_status']);
        $this->assertSame('Partly fulfilled', $row['workflow_label']);

        // Paid once each expense is verified.
        AuditLogger::muted(fn () => Expense::whereKey($first)->update(['workflow_status' => 'verified', 'status' => 'verified']));
        $row = $this->requestRow();
        $this->assertSame(['paid', 'linked', 'linked', 'linked'], array_column($row['items'], 'status'));
        $this->assertSame('Paid', $row['items'][0]['status_label']);
        $this->assertSame('Partly fulfilled', $row['workflow_label']);

        AuditLogger::muted(fn () => Expense::where('purchase_request_id', $this->request->id)->update(['workflow_status' => 'verified', 'status' => 'verified']));
        $row = $this->requestRow();
        $this->assertSame('Fulfilled', $row['workflow_label']);
        $this->assertSame('full', $row['fulfilment']);
    }

    public function test_items_on_different_line_items_are_refused_in_one_expense(): void
    {
        [$microscope, , $containers] = $this->items->all();

        $this->record($this->equipment, 1000, [$microscope->id, $containers->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.purchase_request_item_ids.0', 'All ticked items must be on one line item. Record a separate expense for Supplies.');
        // Ticked Supplies items on an Equipment expense.
        $this->record($this->equipment, 1000, [$containers->id])
            ->assertStatus(422)->assertJsonValidationErrors(['lib_category_id']);
        // Items of another request, or none of this one.
        $this->record($this->equipment, 1000, [999999])->assertStatus(422)->assertJsonValidationErrors(['purchase_request_item_ids']);
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, PurchaseRequestItem::whereNotNull('expense_id')->count());

        // An item already linked cannot be ticked again.
        $this->record($this->equipment, 80000, [$microscope->id])->assertCreated();
        $this->record($this->equipment, 1000, [$microscope->id])
            ->assertStatus(422)->assertJsonPath('errors.purchase_request_item_ids.0', '“Digital microscope” is already linked to an expense.');
    }

    public function test_the_amount_may_not_exceed_the_ticked_items_and_a_lower_amount_releases_the_difference(): void
    {
        [$microscope] = $this->items->all();
        $before = $this->balancing();
        $this->assertEquals(100000, $before['lines'][$this->equipment->id]['pending']);
        $this->assertEquals(125500, $before['totals']['pending']);

        $this->record($this->equipment, 80000.01, [$microscope->id])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->assertSame(0, Expense::count());

        // The receipt came to 75,000 for an 80,000 item: 5,000 is released.
        $id = $this->record($this->equipment, '75,000.00', [$microscope->id])->assertCreated()->json('expense.id');
        $after = $this->balancing();
        $equipment = $after['lines'][$this->equipment->id];
        $this->assertEquals(75000, $equipment['pending_expenses']);
        $this->assertEquals(20000, $equipment['pending_purchase_requests']); // the centrifuge, still open
        $this->assertEquals(95000, $equipment['pending']);
        $this->assertEquals(105000, $equipment['available']);
        // No double counting: 75,000 + 20,000 + 12,000 + 13,500.
        $this->assertEquals(120500, $after['totals']['pending']);

        // Editing the linked draft cannot go above the items either.
        $this->actingAs($this->assistant)->patchJson("/api/expenses/{$id}", ['amount' => 81000])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->patchJson("/api/expenses/{$id}", ['amount' => 79000])->assertOk();
        $this->assertEquals(99000, $this->balancing()['lines'][$this->equipment->id]['pending']);
    }

    public function test_without_ticked_items_every_open_item_on_the_line_is_taken(): void
    {
        $this->record($this->equipment, 100000, null)->assertCreated();
        $row = $this->requestRow();
        $this->assertSame(['linked', 'linked', 'open', 'open'], array_column($row['items'], 'status'));
    }

    public function test_cancelling_a_partly_linked_request_releases_only_its_open_items(): void
    {
        [$microscope] = $this->items->all();
        $this->record($this->equipment, 75000, [$microscope->id])->assertCreated();

        $this->actingAs($this->finance)->postJson("/api/purchase-requests/{$this->request->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$this->request->id}/cancel", ['reason' => ''])->assertStatus(422);
        $this->postJson("/api/purchase-requests/{$this->request->id}/cancel", ['reason' => 'Centrifuge no longer needed.'])
            ->assertOk()
            ->assertJsonPath('purchase_request.workflow_status', 'cancelled')
            ->assertJsonPath('purchase_request.workflow_label', 'Cancelled (partly fulfilled)')
            ->assertJsonPath('purchase_request.items.0.status', 'linked')
            ->assertJsonPath('purchase_request.items.1.status', 'open');

        $after = $this->balancing();
        $this->assertEquals(75000, $after['lines'][$this->equipment->id]['pending']); // the expense only
        $this->assertEquals(0, $after['lines'][$this->equipment->id]['pending_purchase_requests']);
        $this->assertEquals(0, $after['lines'][$this->lineItem->id]['pending']);
        $this->assertEquals(0, $after['lines'][$this->travel->id]['pending']);
        $this->assertEquals(75000, $after['totals']['pending']);

        // A cancelled request takes no more links; its linked item keeps its expense.
        $this->record($this->lineItem, 1000, [$this->items[2]->id])->assertStatus(422)->assertJsonValidationErrors(['purchase_request_id']);
        $this->assertNotNull($microscope->fresh()->expense_id);
    }

    public function test_the_project_leaders_review_panel_offers_cancel_only_for_approved_or_partly_fulfilled_requests(): void
    {
        $panel = fn (PurchaseRequest $request) => $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/review-queue/purchase_request/{$request->id}")
            ->assertOk()->json('item');

        // Approved, nothing linked.
        $this->assertTrue($panel($this->request)['can_cancel']);

        // Partly fulfilled.
        [$microscope] = $this->items->all();
        $this->record($this->equipment, 75000, [$microscope->id])->assertCreated();
        $item = $panel($this->request);
        $this->assertSame('Partly fulfilled', $item['workflow_label']);
        $this->assertTrue($item['can_cancel']);

        // Not offered before approval.
        $submitted = AuditLogger::muted(fn () => PurchaseRequest::create([
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
            'description' => 'Gloves', 'quantity' => 1, 'estimated_amount' => 500, 'workflow_status' => 'submitted', 'submitted_at' => now(),
        ]));
        $this->assertFalse($panel($submitted)['can_cancel']);

        // The Project Leader cancels through the same endpoint, with a reason (required).
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$this->request->id}/cancel", ['reason' => ' '])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/purchase-requests/{$this->request->id}/cancel", ['reason' => 'Supplier closed.'])
            ->assertOk()->assertJsonPath('purchase_request.workflow_label', 'Cancelled (partly fulfilled)');
        $this->assertFalse($panel($this->request->fresh())['can_cancel']);
        $this->assertSame('cancelled', AuditTrail::latest('id')->value('action'));
        $this->assertSame(1, $this->assistant->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === 'cancelled')->count());

        // A fulfilled request (no open item) is not offered either.
        $other = $this->approvedSingle();
        $this->record($this->lineItem, 1000, null, ['purchase_request_id' => $other->id])->assertCreated();
        $this->assertSame('fulfilled', $other->fresh()->workflow_status);
        $this->assertFalse($panel($other->fresh())['can_cancel']);
    }

    public function test_a_returned_expense_keeps_its_items_and_deleting_the_draft_frees_them(): void
    {
        [$microscope, $centrifuge] = $this->items->all();
        $id = $this->record($this->equipment, 100000, [$microscope->id, $centrifuge->id])->assertCreated()->json('expense.id');
        $this->assertSame('endorsed', $this->request->fresh()->workflow_status); // Supplies and Travel items are still open

        // Returned (by the Project Leader, or rejected by Finance/Admin): items stay linked.
        AuditLogger::muted(fn () => Expense::whereKey($id)->update(['workflow_status' => 'returned', 'status' => 'rejected']));
        $this->assertSame(['linked', 'linked', 'open', 'open'], array_column($this->requestRow()['items'], 'status'));
        $this->assertEquals(100000, $this->balancing()['lines'][$this->equipment->id]['pending_expenses']);

        $this->actingAs($this->assistant)->deleteJson("/api/expenses/{$id}")->assertOk();
        $this->assertSame(['open', 'open', 'open', 'open'], array_column($this->requestRow()['items'], 'status'));
        $this->assertSame('unlinked', AuditTrail::latest('id')->value('action'));
        $lines = $this->balancing()['lines'];
        $this->assertEquals(0, $lines[$this->equipment->id]['pending_expenses']);
        $this->assertEquals(100000, $lines[$this->equipment->id]['pending_purchase_requests']);
        $this->assertSame('Approved by Project Leader', $this->requestRow()['workflow_label']);

        // The freed items can be linked again (at most the centrifuge's 20,000).
        $this->record($this->equipment, 20000, [$centrifuge->id])->assertCreated();
    }

    public function test_the_migration_links_items_of_expenses_linked_before(): void
    {
        [$microscope, $centrifuge, $containers] = $this->items->all();
        $expense = AuditLogger::muted(fn () => Expense::create([
            'project_id' => $this->project->id, 'lib_category_id' => $this->equipment->id, 'purchase_request_id' => $this->request->id,
            'recorded_by' => $this->assistant->id, 'description' => 'Equipment', 'amount' => 100000, 'expense_date' => '2026-11-10',
            'workflow_status' => 'draft', 'status' => 'pending',
        ]));

        $migration = require database_path('migrations/2026_12_01_000001_add_expense_id_to_purchase_request_items.php');
        $migration->backfill();
        $migration->backfill();

        $this->assertSame([$expense->id, $expense->id, null], [
            $microscope->fresh()->expense_id, $centrifuge->fresh()->expense_id, $containers->fresh()->expense_id,
        ]);
    }

    // ------------------------------------------------------------ helpers

    /** @param array<int, int>|null $itemIds */
    private function record(LibCategory $line, $amount, ?array $itemIds, array $extra = [])
    {
        return $this->actingAs($this->assistant)->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(array_merge([
            'lib_category_id' => $line->id, 'amount' => $amount, 'purchase_request_id' => $this->request->id,
        ], $itemIds === null ? [] : ['purchase_request_item_ids' => $itemIds], $extra)));
    }

    /** A one-item approved request on Supplies (1,000). */
    private function approvedSingle(): PurchaseRequest
    {
        return AuditLogger::muted(fn () => PurchaseRequest::create([
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
            'description' => 'Pipettes', 'quantity' => 1, 'estimated_amount' => 1000, 'workflow_status' => 'endorsed',
            'pl_reviewed_by' => $this->projectLeader->id, 'pl_reviewed_at' => now(),
        ]));
    }

    private function requestRow(): array
    {
        $rows = $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/purchase-requests")->assertOk()->json('purchase_requests');

        return collect($rows)->firstWhere('id', $this->request->id);
    }

    /** @return array{lines: array<int, array<string, mixed>>, totals: array<string, mixed>} */
    private function balancing(): array
    {
        $data = $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/balancing")->assertOk()->json();

        return ['lines' => collect($data['line_items'])->keyBy('id')->all(), 'totals' => $data['totals']];
    }
}

<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\ProjectPersonnel;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Purchase requests as MSU's Purchase Request form: several items, each
 * charged to its own line item; total = sum of quantity x unit cost; a
 * per-line-item budget check; honoraria lines refused; PR / SAI / ALOBS
 * numbers set by Finance/Admin.
 */
class PurchaseRequestItemsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private LibCategory $equipment;
    private LibCategory $travel;

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
    }

    public function test_the_msu_example_totals_105500_across_three_line_items(): void
    {
        $id = $this->actingAs($this->assistant)
            ->postJson($this->url(), $this->example())
            ->assertCreated()
            ->assertJsonPath('purchase_request.purpose', 'Laboratory set-up for the field study')
            ->assertJsonPath('purchase_request.total', 105500)
            ->assertJsonPath('purchase_request.line_item', '3 line items')
            ->assertJsonCount(3, 'purchase_request.items')
            ->assertJsonPath('purchase_request.items.0.estimated_cost', 80000)
            ->assertJsonPath('purchase_request.items.1.estimated_cost', 12000)
            ->assertJsonPath('purchase_request.items.2.estimated_cost', 13500)
            ->assertJsonPath('purchase_request.items.0.unit', 'unit')
            ->json('purchase_request.id');

        $request = PurchaseRequest::findOrFail($id);
        $this->assertSame('105500.00', (string) $request->estimated_amount);
        $this->assertSame(5, $request->quantity);
        $this->assertSame($this->equipment->id, (int) $request->lib_category_id);
        $this->assertSame(3, $request->items()->count());

        // Approved: each line item holds its own items as pending.
        $this->approve($request);
        $lines = $this->balancing();
        $this->assertEquals(80000.0, $lines[$this->equipment->id]['pending_purchase_requests']);
        $this->assertEquals(12000.0, $lines[$this->lineItem->id]['pending_purchase_requests']);
        $this->assertEquals(13500.0, $lines[$this->travel->id]['pending_purchase_requests']);
        $this->assertEquals(36500.0, $lines[$this->travel->id]['available']);
    }

    public function test_estimated_cost_is_computed_and_comma_amounts_are_accepted(): void
    {
        $payload = $this->example();
        $payload['items'][0]['unit_cost'] = '₱40,000.00';
        $payload['items'][0]['estimated_cost'] = 1; // ignored: computed
        $payload['items'][1]['unit_cost'] = '6,000';
        $payload['items'][2]['unit_cost'] = '13,500.00';

        $this->actingAs($this->assistant)->postJson($this->url(), $payload)
            ->assertCreated()
            ->assertJsonPath('purchase_request.items.0.unit_cost', 40000)
            ->assertJsonPath('purchase_request.items.0.estimated_cost', 80000)
            ->assertJsonPath('purchase_request.total', 105500);

        // Centavos never drift: 3 x 33.33 = 99.99.
        $this->postJson($this->url(), $this->payload([['lib_category_id' => $this->lineItem->id, 'quantity' => 3, 'unit' => 'pc', 'description' => 'Pens', 'unit_cost' => '33.33']]))
            ->assertCreated()->assertJsonPath('purchase_request.total', 99.99);
    }

    public function test_items_are_validated(): void
    {
        $this->actingAs($this->assistant);
        $this->postJson($this->url(), $this->payload([]))->assertStatus(422)->assertJsonPath('errors.items.0', 'Add at least one item.');
        $this->postJson($this->url(), ['purpose' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['lib_category_id']);

        $bad = $this->payload([[
            'lib_category_id' => $this->lineItem->id, 'quantity' => 0, 'unit' => 'kilo', 'description' => '', 'unit_cost' => '1,23.4',
        ]]);
        $this->postJson($this->url(), $bad)->assertStatus(422)->assertJsonValidationErrors([
            'items.0.quantity', 'items.0.unit', 'items.0.description', 'items.0.unit_cost',
        ]);
        $this->postJson($this->url(), array_merge($this->example(), ['purpose' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['description']);
        $this->assertSame(0, PurchaseRequest::count());
    }

    public function test_the_budget_check_is_per_line_item_and_counts_pending(): void
    {
        // Travel has 50,000. An approved request already holds 40,000 of it.
        $held = $this->create([['lib_category_id' => $this->travel->id, 'quantity' => 1, 'unit' => 'lot', 'description' => 'Fieldwork trip', 'unit_cost' => 40000]]);
        $this->approve($held);

        // 2 x 6,000 = 12,000 on Travel is more than the 10,000 left, although the
        // Supplies item fits and the request total is far below the project budget.
        $request = $this->create([
            ['lib_category_id' => $this->lineItem->id, 'quantity' => 1, 'unit' => 'box', 'description' => 'Gloves', 'unit_cost' => 1000],
            ['lib_category_id' => $this->travel->id, 'quantity' => 2, 'unit' => 'lot', 'description' => 'Van rental', 'unit_cost' => 6000],
        ]);
        $this->attachQuotation($request);
        $message = $this->actingAs($this->assistant)->postJson("/api/purchase-requests/{$request->id}/submit")
            ->assertStatus(422)->json('errors.items.0');
        $this->assertStringContainsString('Travel total ₱12,000.00, more than the ₱10,000.00 available', $message);
        $this->assertStringNotContainsString('Supplies', $message);
        $this->assertSame('draft', $request->fresh()->workflow_status);

        // Lowered to fit: submitted.
        $this->patchJson("/api/purchase-requests/{$request->id}", ['items' => [
            ['lib_category_id' => $this->lineItem->id, 'quantity' => 1, 'unit' => 'box', 'description' => 'Gloves', 'unit_cost' => 1000],
            ['lib_category_id' => $this->travel->id, 'quantity' => 2, 'unit' => 'lot', 'description' => 'Van rental', 'unit_cost' => '5,000'],
        ]])->assertOk()->assertJsonPath('purchase_request.total', 11000);
        $this->postJson("/api/purchase-requests/{$request->id}/submit")->assertOk();

        // Approval checks again: another request took the rest of Travel meanwhile.
        $other = $this->create([['lib_category_id' => $this->travel->id, 'quantity' => 1, 'unit' => 'lot', 'description' => 'Fuel', 'unit_cost' => 5000]]);
        $this->approve($other);
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$request->id}/endorse")
            ->assertStatus(422)->assertJsonValidationErrors(['items']);
        $this->assertSame('submitted', $request->fresh()->workflow_status);
    }

    public function test_a_line_item_shared_by_several_items_is_checked_on_their_sum(): void
    {
        // 30,000 + 30,000 on Travel (50,000): each fits alone, together they do not.
        $request = $this->create([
            ['lib_category_id' => $this->travel->id, 'quantity' => 1, 'unit' => 'lot', 'description' => 'Trip 1', 'unit_cost' => 30000],
            ['lib_category_id' => $this->travel->id, 'quantity' => 1, 'unit' => 'lot', 'description' => 'Trip 2', 'unit_cost' => 30000],
        ]);
        $this->attachQuotation($request);
        $this->actingAs($this->assistant)->postJson("/api/purchase-requests/{$request->id}/submit")
            ->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_honoraria_lines_are_refused_and_other_ps_lines_accepted(): void
    {
        [$honoraria, $ps] = AuditLogger::muted(function () {
            $honoraria = LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'PS', 'line_item_name' => 'Honoraria', 'allocated_amount' => 100000]);
            $ps = LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'PS', 'line_item_name' => 'Overtime meals', 'allocated_amount' => 10000]);
            ProjectPersonnel::create([
                'project_id' => $this->project->id, 'user_id' => $this->researcher->id, 'lib_category_id' => $honoraria->id,
                'position' => 'Project Staff', 'special_order_no' => 'SO 1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
                'monthly_honorarium' => 10000,
            ]);

            return [$honoraria, $ps];
        });

        $this->actingAs($this->assistant)->postJson($this->url(), $this->payload([
            ['lib_category_id' => $this->lineItem->id, 'quantity' => 1, 'unit' => 'pc', 'description' => 'Folder', 'unit_cost' => 50],
            ['lib_category_id' => $honoraria->id, 'quantity' => 1, 'unit' => 'lot', 'description' => 'Pay', 'unit_cost' => 1000],
        ]))->assertStatus(422)->assertJsonPath('errors', ['items.1.lib_category_id' => ['Honoraria are paid through Payroll. Choose another line item for this item.']]);
        $this->assertSame(0, PurchaseRequest::count());

        $this->postJson($this->url(), $this->payload([
            ['lib_category_id' => $ps->id, 'quantity' => 4, 'unit' => 'set', 'description' => 'Meals', 'unit_cost' => 250],
        ]))->assertCreated()->assertJsonPath('purchase_request.total', 1000);
    }

    public function test_expenses_link_per_line_item_and_the_request_is_fulfilled_when_all_are_linked(): void
    {
        $request = $this->create($this->example()['items']);
        $this->approve($request);
        $this->actingAs($this->assistant);
        $record = fn (LibCategory $line, float $amount) => $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload([
            'lib_category_id' => $line->id, 'amount' => $amount, 'purchase_request_id' => $request->id,
        ]));

        $record($this->equipment, 79000)->assertCreated();
        $this->assertSame('endorsed', $request->fresh()->workflow_status);
        $lines = $this->balancing();
        // Equipment: the linked expense counts instead of the request's 80,000.
        $this->assertEquals(0.0, $lines[$this->equipment->id]['pending_purchase_requests']);
        $this->assertEquals(79000.0, $lines[$this->equipment->id]['pending_expenses']);
        $this->assertEquals(12000.0, $lines[$this->lineItem->id]['pending_purchase_requests']);

        $record($this->equipment, 1000)->assertStatus(422)->assertJsonPath('errors.purchase_request_id.0', "This purchase request's items on this line item are already linked to another expense.");
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$request->id}/cancel", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['workflow_status']);

        $this->actingAs($this->assistant);
        $record($this->lineItem, 12000)->assertCreated();
        $record($this->travel, 13500)->assertCreated();
        $this->assertSame('fulfilled', $request->fresh()->workflow_status);
        $this->assertEquals(0.0, $this->balancing()[$this->travel->id]['pending_purchase_requests']);
    }

    public function test_review_panel_and_my_submissions_show_the_item_table_and_total(): void
    {
        $request = $this->create($this->example()['items']);
        $this->attachQuotation($request);
        $this->actingAs($this->assistant)->postJson("/api/purchase-requests/{$request->id}/submit")->assertOk();

        $mine = $this->getJson("/api/projects/{$this->project->id}/my-submissions/purchase_request/{$request->id}")->assertOk();
        $item = $mine->json('item') ?? $mine->json();
        $this->assertSame(105500, $item['pr_total']);
        $this->assertCount(3, $item['pr_items']);
        $this->assertSame('Laboratory equipment', $item['pr_items'][0]['line_item']);

        $queue = $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/review-queue?tab=waiting&type=purchase_request")->assertOk();
        $row = collect($queue->json('items'))->firstWhere('id', $request->id);
        $this->assertSame(105500, $row['pr_total']);
        $this->assertSame([2, 2, 1], array_column($row['pr_items'], 'quantity'));

        // The Program Leader reads the list with items.
        $list = $this->actingAs($this->programLeader)->getJson($this->url())->assertOk()->json('purchase_requests');
        $this->assertSame([80000, 12000, 13500], array_column($list[0]['items'], 'estimated_cost'));
    }

    public function test_old_single_amount_requests_become_one_item_each(): void
    {
        $insert = fn (array $row) => DB::table('purchase_requests')->insertGetId(array_merge([
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
            'funding_source' => 'funder', 'workflow_status' => 'endorsed', 'created_at' => now(), 'updated_at' => now(),
        ], $row));
        $even = $insert(['description' => 'Glassware set', 'quantity' => 3, 'estimated_amount' => 4500]);
        $uneven = $insert(['description' => 'Pipette tips', 'quantity' => 3, 'estimated_amount' => 100]);
        $deleted = $insert(['description' => 'Old draft', 'quantity' => 1, 'estimated_amount' => 50, 'workflow_status' => 'draft', 'deleted_at' => now()]);
        $before = $this->balancing()[$this->lineItem->id]['pending_purchase_requests'];
        $this->assertEquals(4600.0, $before); // read from the request columns while there are no items

        $migration = require database_path('migrations/2026_11_30_000001_add_items_and_document_numbers_to_purchase_requests.php');
        $migration->backfill();
        $migration->backfill(); // once only

        $items = PurchaseRequestItem::whereIn('purchase_request_id', [$even, $uneven, $deleted])->get()->keyBy('purchase_request_id');
        $this->assertCount(3, $items);
        $this->assertSame([3, 'lot', 'Glassware set', '1500.00', '4500.00'], [$items[$even]->quantity, $items[$even]->unit, $items[$even]->description, (string) $items[$even]->unit_cost, (string) $items[$even]->estimated_cost]);
        // Not exact: the unit cost is rounded, the amount is kept to the centavo.
        $this->assertSame(['33.33', '100.00'], [(string) $items[$uneven]->unit_cost, (string) $items[$uneven]->estimated_cost]);
        $this->assertSame($this->lineItem->id, (int) $items[$deleted]->lib_category_id);

        $this->assertEquals($before, $this->balancing()[$this->lineItem->id]['pending_purchase_requests']);
        $this->assertSame('4500.00', (string) PurchaseRequest::find($even)->estimated_amount);
        $this->actingAs($this->assistant)->getJson($this->url())->assertOk();
    }

    public function test_finance_admin_sets_the_pr_sai_and_alobs_numbers(): void
    {
        $request = $this->create($this->example()['items']);
        $numbers = [
            'pr_no' => ' 2026-11-0123 ', 'pr_date' => '2026-11-20',
            'sai_no' => 'SAI-0456', 'sai_date' => '2026-11-21',
            'alobs_no' => '02-101-2026-11-789', 'alobs_date' => '2026-11-22',
        ];
        $url = "/api/purchase-requests/{$request->id}/numbers";

        // Not yet approved.
        $this->actingAs($this->finance)->patchJson($url, $numbers)->assertStatus(422)->assertJsonValidationErrors(['workflow_status']);
        $this->approve($request);

        foreach ([$this->assistant, $this->projectLeader, $this->researcher, $this->programLeader, $this->outsider] as $user) {
            $this->actingAs($user)->patchJson($url, $numbers)->assertForbidden();
        }
        $this->assertNull($request->fresh()->pr_no);

        $this->actingAs($this->finance)->patchJson($url, ['pr_date' => '20266-11-20'])
            ->assertStatus(422)->assertJsonPath('errors.pr_date.0', 'Enter a date between 2000 and 2100.');

        $audit = AuditTrail::count();
        $this->patchJson($url, $numbers)
            ->assertOk()
            ->assertJsonPath('purchase_request.pr_no', '2026-11-0123')
            ->assertJsonPath('purchase_request.pr_date', '2026-11-20')
            ->assertJsonPath('purchase_request.sai_no', 'SAI-0456')
            ->assertJsonPath('purchase_request.alobs_no', '02-101-2026-11-789')
            ->assertJsonPath('purchase_request.alobs_date', '2026-11-22');
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame(['updated', 'PurchaseRequest'], [AuditTrail::latest('id')->value('action'), AuditTrail::latest('id')->value('model_type')]);

        // Shown on the record panel.
        $fields = collect($this->actingAs($this->assistant)->getJson("/api/projects/{$this->project->id}/my-submissions/purchase_request/{$request->id}")->json('item.fields') ?? []);
        $this->assertSame('2026-11-0123 · Nov 20, 2026', $fields->firstWhere('label', 'PR No.')['value'] ?? null);
    }

    // ------------------------------------------------------------ helpers

    private function url(): string
    {
        return "/api/projects/{$this->project->id}/purchase-requests";
    }

    private function payload(array $items): array
    {
        return ['purpose' => 'Laboratory set-up for the field study', 'supplier' => 'ABC Lab Supply', 'items' => $items];
    }

    /** The real request: 2 x 40,000 + 2 x 6,000 + 1 x 13,500 = 105,500.00. */
    private function example(): array
    {
        return $this->payload([
            ['lib_category_id' => $this->equipment->id, 'quantity' => 2, 'unit' => 'unit', 'description' => 'Digital microscope', 'unit_cost' => 40000],
            ['lib_category_id' => $this->lineItem->id, 'quantity' => 2, 'unit' => 'box', 'description' => 'Specimen containers', 'unit_cost' => 6000],
            ['lib_category_id' => $this->travel->id, 'quantity' => 1, 'unit' => 'lot', 'description' => 'Transport of samples', 'unit_cost' => 13500],
        ]);
    }

    private function create(array $items): PurchaseRequest
    {
        $id = $this->actingAs($this->assistant)->postJson($this->url(), $this->payload($items))->assertCreated()->json('purchase_request.id');

        return PurchaseRequest::findOrFail($id);
    }

    private function attachQuotation(PurchaseRequest $request): void
    {
        AuditLogger::muted(fn () => $request->documents()->create([
            'uploaded_by' => $this->assistant->id, 'document_type' => 'quotation', 'file_name' => 'q.pdf',
            'file_path' => 'documents/purchase-requests/q.pdf', 'disk' => 'local', 'uploaded_at' => now(),
        ]));
    }

    private function approve(PurchaseRequest $request): void
    {
        $this->attachQuotation($request);
        $this->actingAs($this->assistant)->postJson("/api/purchase-requests/{$request->id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$request->id}/endorse")->assertOk();
    }

    /** @return array<int, array<string, mixed>> balancing rows by line item id */
    private function balancing(): array
    {
        return collect($this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/balancing")->assertOk()->json('line_items'))
            ->keyBy('id')->all();
    }
}

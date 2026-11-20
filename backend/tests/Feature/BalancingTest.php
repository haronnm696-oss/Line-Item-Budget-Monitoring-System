<?php

namespace Tests\Feature;

use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\PurchaseRequest;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Balancing query per line item: allocated, spent (verified expenses and
 * verified payroll), pending (submitted / approved expenses and payroll,
 * unverified expenses linked to a purchase request, and approved open
 * purchase requests) and available = allocated - spent - pending. The same
 * money is never counted twice.
 */
class BalancingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    public function test_the_numbers_per_line_item(): void
    {
        $this->buildWorkflowFixtures();

        $ps = AuditLogger::muted(function () {
            // Supplies (500,000 allocated).
            $this->expenseIn('verified', null, true, ['amount' => 1000]);
            $this->expenseIn('verified', null, true, ['amount' => 250.50]);
            $this->expenseIn('submitted', null, true, ['amount' => 200]);
            $this->expenseIn('endorsed', null, true, ['amount' => 300]);
            $this->expenseIn('draft', null, true, ['amount' => 999]);      // not pending
            $this->expenseIn('returned', null, true, ['amount' => 50]);    // not pending
            $this->expenseIn('submitted', null, true, ['amount' => 777])->delete(); // deleted: ignored
            // Only an approved, open request counts: submitted, draft, returned,
            // fulfilled and cancelled ones do not.
            foreach (['submitted' => 400, 'endorsed' => 100, 'draft' => 77, 'returned' => 5, 'fulfilled' => 3000, 'cancelled' => 2000] as $stage => $amount) {
                PurchaseRequest::create([
                    'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
                    'description' => "PR {$stage}", 'quantity' => 1, 'estimated_amount' => $amount, 'workflow_status' => $stage,
                ]);
            }

            // A PS line with payroll: verified is spent (funder share), submitted
            // and approved are pending, draft and returned count nowhere.
            $ps = LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'PS', 'line_item_name' => 'Salaries', 'allocated_amount' => 60000]);
            foreach (['verified' => [11, 30000], 'submitted' => [10, 5000], 'endorsed' => [9, 4000], 'draft' => [8, 700], 'returned' => [7, 60]] as $stage => [$month, $amount]) {
                Payroll::create([
                    'project_id' => $this->project->id, 'lib_category_id' => $ps->id, 'personnel_id' => $this->researcher->id,
                    'computed_by' => $this->assistant->id, 'pay_month' => $month, 'pay_year' => 2026, 'basic_rate' => $amount,
                    'gross_pay' => $amount, 'net_pay' => $amount, 'status' => $stage === 'verified' ? 'approved' : 'draft', 'workflow_status' => $stage,
                ]);
            }

            return $ps;
        });

        $response = $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/balancing")->assertOk();
        $rows = collect($response->json('line_items'))->keyBy('id');

        $supplies = $rows[$this->lineItem->id];
        $this->assertSame(500000, $supplies['allocated']);
        $this->assertSame(1250.5, $supplies['spent']);
        $this->assertSame(500, $supplies['pending_expenses']);
        $this->assertSame(100, $supplies['pending_purchase_requests']);
        $this->assertSame(600, $supplies['pending']);
        $this->assertSame(498149.5, $supplies['available']);

        $salaries = $rows[$ps->id];
        $this->assertSame([60000, 30000, 9000, 21000], [$salaries['allocated'], $salaries['spent'], $salaries['pending'], $salaries['available']]);
        $this->assertSame([30000, 9000], [$salaries['spent_payroll'], $salaries['pending_payroll']]);
        $this->assertSame([30000, 9000], [$salaries['sources']['funder']['spent'], $salaries['sources']['funder']['pending']]);
        $this->assertSame([0, 0], [$salaries['sources']['implementing_agency']['spent'], $salaries['sources']['implementing_agency']['pending']]);

        $response->assertJsonPath('totals.allocated', 560000)
            ->assertJsonPath('totals.spent', 31250.5)
            ->assertJsonPath('totals.pending', 9600)
            ->assertJsonPath('totals.pending_payroll', 9000)
            ->assertJsonPath('totals.available', 519149.5);

        // One line item.
        $this->getJson("/api/projects/{$this->project->id}/balancing?line_item={$ps->id}")
            ->assertOk()->assertJsonCount(1, 'line_items')->assertJsonPath('line_items.0.name', 'Salaries');

        // A line item of another project, and an outsider.
        $foreign = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->otherProject->id, 'category_type' => 'MOOE', 'line_item_name' => 'X', 'allocated_amount' => 1,
        ]));
        $this->getJson("/api/projects/{$this->project->id}/balancing?line_item={$foreign->id}")->assertStatus(422);
        $this->actingAs($this->outsider)->getJson("/api/projects/{$this->project->id}/balancing")->assertForbidden();
    }

    public function test_linking_an_expense_moves_the_money_from_the_request_without_double_counting(): void
    {
        $this->buildWorkflowFixtures();
        $request = $this->approvedRequest(10000);
        $this->assertLine(0, 10000, 490000);

        // Record an expense that fulfils it: the request stops counting and
        // the (still draft) expense counts instead: 9,500, not 19,500.
        $audit = \App\Models\AuditTrail::count();
        $id = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['amount' => 9500, 'purchase_request_id' => $request->id]))
            ->assertCreated()
            ->assertJsonPath('expense.purchase_request_id', $request->id)
            ->json('expense.id');
        $this->assertSame('fulfilled', $request->fresh()->workflow_status);
        $this->assertSame($audit + 2, \App\Models\AuditTrail::count()); // expense created + request fulfilled
        $this->assertSame(['created', 'fulfilled'], \App\Models\AuditTrail::orderBy('id')->get()->slice(-2)->pluck('action')->values()->all());
        $this->assertCount(1, $this->projectLeader->notifications()->get()->filter(fn ($n) => $n->data['event'] === 'fulfilled'));
        $this->assertLine(0, 9500, 490500);

        // Through the workflow: pending until verified, then spent.
        $expense = \App\Models\Expense::findOrFail($id);
        $this->receiptFor($expense, $this->assistant);
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$id}/submit")->assertOk();
        $this->assertLine(0, 9500, 490500);
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$id}/endorse")->assertOk();
        $this->assertLine(0, 9500, 490500);
        $this->actingAs($this->finance)->postJson("/api/expenses/{$id}/reject", ['reason' => 'Wrong OR number.'])->assertOk();
        $this->assertLine(0, 9500, 490500); // returned but still linked: still pending
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$id}/endorse")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/expenses/{$id}/verify")->assertOk();
        $this->assertLine(9500, 0, 490500);
    }

    public function test_cancelling_an_approved_request_releases_its_pending_amount(): void
    {
        $this->buildWorkflowFixtures();
        $request = $this->approvedRequest(8000);
        $this->assertLine(0, 8000, 492000);

        $this->actingAs($this->assistant)
            ->postJson("/api/purchase-requests/{$request->id}/cancel", ['reason' => 'Supplier closed.'])
            ->assertOk()
            ->assertJsonPath('purchase_request.workflow_status', 'cancelled');
        $this->assertLine(0, 0, 500000);
    }

    public function test_deleting_a_linked_draft_reopens_the_request(): void
    {
        $this->buildWorkflowFixtures();
        $request = $this->approvedRequest(6000);
        $id = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['amount' => 5000, 'purchase_request_id' => $request->id]))
            ->assertCreated()->json('expense.id');
        $this->assertLine(0, 5000, 495000);

        $this->actingAs($this->assistant)->deleteJson("/api/expenses/{$id}")->assertOk();
        $this->assertSame('endorsed', $request->fresh()->workflow_status);
        $this->assertSame('reopened', \App\Models\AuditTrail::latest('id')->value('action'));
        $this->assertLine(0, 6000, 494000);
    }

    private function approvedRequest(float $amount): PurchaseRequest
    {
        return AuditLogger::muted(fn () => PurchaseRequest::create([
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
            'description' => 'Reagents', 'quantity' => 1, 'estimated_amount' => $amount, 'workflow_status' => 'endorsed',
            'pl_reviewed_by' => $this->projectLeader->id, 'pl_reviewed_at' => now(),
        ]));
    }

    /** Supplies line (500,000 allocated): spent, pending, available. */
    private function assertLine(float $spent, float $pending, float $available): void
    {
        $line = $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/balancing?line_item={$this->lineItem->id}")
            ->assertOk()
            ->json('line_items.0');
        $this->assertEquals([$spent, $pending, $available], [$line['spent'], $line['pending'], $line['available']]);
        $this->assertEquals(500000 - $spent - $pending, $line['available']);
    }
}

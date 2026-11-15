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
 * Balancing query per line item: allocated, spent (verified), pending
 * (submitted + endorsed expenses and purchase requests) and
 * available = allocated - spent - pending. Payroll is not included.
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
            foreach (['submitted' => 400, 'endorsed' => 100, 'draft' => 77, 'returned' => 5] as $stage => $amount) {
                PurchaseRequest::create([
                    'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $this->assistant->id,
                    'description' => "PR {$stage}", 'quantity' => 1, 'estimated_amount' => $amount, 'workflow_status' => $stage,
                ]);
            }

            // A PS line with verified payroll: payroll is not spent.
            $ps = LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'PS', 'line_item_name' => 'Salaries', 'allocated_amount' => 60000]);
            Payroll::create([
                'project_id' => $this->project->id, 'lib_category_id' => $ps->id, 'personnel_id' => $this->researcher->id,
                'computed_by' => $this->assistant->id, 'pay_month' => 11, 'pay_year' => 2026, 'basic_rate' => 30000,
                'gross_pay' => 30000, 'net_pay' => 30000, 'status' => 'approved', 'workflow_status' => 'verified',
            ]);

            return $ps;
        });

        $response = $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/balancing")->assertOk();
        $rows = collect($response->json('line_items'))->keyBy('id');

        $supplies = $rows[$this->lineItem->id];
        $this->assertSame(500000, $supplies['allocated']);
        $this->assertSame(1250.5, $supplies['spent']);
        $this->assertSame(500, $supplies['pending_expenses']);
        $this->assertSame(500, $supplies['pending_purchase_requests']);
        $this->assertSame(1000, $supplies['pending']);
        $this->assertSame(497749.5, $supplies['available']);

        $salaries = $rows[$ps->id];
        $this->assertSame([60000, 0, 0, 60000], [$salaries['allocated'], $salaries['spent'], $salaries['pending'], $salaries['available']]);

        $response->assertJsonPath('totals.allocated', 560000)
            ->assertJsonPath('totals.spent', 1250.5)
            ->assertJsonPath('totals.pending', 1000)
            ->assertJsonPath('totals.available', 557749.5);

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
}

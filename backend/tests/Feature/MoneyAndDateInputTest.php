<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\ProjectTranche;
use App\Rules\DateInRange;
use App\Services\AuditLogger;
use App\Support\MoneyInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Peso amounts typed with commas ("3,178,376.00", "₱ 2,000,000") are
 * accepted and stored as numbers on every money field; dates and months
 * outside 2000–2100 (six-digit years such as 222222-12-15) are refused with
 * one clear message.
 */
class MoneyAndDateInputTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    public function test_the_normaliser_strips_peso_signs_commas_and_group_spaces_only(): void
    {
        $this->assertSame('3178376.00', MoneyInput::normalize('3,178,376.00'));
        $this->assertSame('2000000', MoneyInput::normalize('₱ 2,000,000'));
        $this->assertSame('2000000', MoneyInput::normalize('2 000 000'));
        $this->assertSame('1500.5', MoneyInput::normalize(' 1500.5 '));
        $this->assertSame(1500.5, MoneyInput::normalize(1500.5));
        $this->assertNull(MoneyInput::normalize(null));
        // Not a well-formed amount: left as sent, so validation refuses it.
        $this->assertSame('1,23.456', MoneyInput::normalize('1,23.456'));
        $this->assertSame('12,3456', MoneyInput::normalize('12,3456'));
        $this->assertSame('abc', MoneyInput::normalize('abc'));

        // Only peso fields, at any depth; other text is never touched.
        $this->assertSame(
            ['amount' => '1000', 'description' => '1,000 pens', 'items' => [['amount' => '2500.50', 'reference_no' => '1,234']]],
            MoneyInput::normalizeInput(['amount' => '1,000', 'description' => '1,000 pens', 'items' => [['amount' => '2,500.50', 'reference_no' => '1,234']]])
        );
    }

    public function test_project_amounts_with_commas_are_saved_as_numbers(): void
    {
        $this->actingAs($this->programLeader);
        $project = "/api/program/projects/{$this->project->id}";

        $this->patchJson($project, ['total_approved_budget' => '3,178,376.00', 'funding_agency_amount' => '₱ 2,000,000'])->assertOk();
        $fresh = $this->project->fresh();
        $this->assertSame('3178376.00', (string) $fresh->total_approved_budget);
        $this->assertSame('2000000.00', (string) $fresh->funding_agency_amount);

        $this->postJson("{$project}/tranches", ['amount' => '1,400,000.00', 'planned_release_date' => '2026-02-01'])->assertCreated()
            ->assertJsonPath('tranche.amount', 1400000);

        $this->postJson("{$project}/line-items", [
            'category_type' => 'MOOE', 'cost_type' => 'Direct', 'expense_group' => 'Supplies and Materials Expenses',
            'line_item_name' => 'Laboratory glassware', 'implementing_agency_amount' => '1,000', 'funder_amount' => '₱12,500.50',
        ])->assertCreated()->assertJsonPath('line_item.funder_amount', 12500.5)->assertJsonPath('line_item.implementing_agency_amount', 1000);

        // Still two decimals at most, and malformed grouping is refused.
        $this->patchJson($project, ['total_approved_budget' => '3,178,376.005'])->assertStatus(422)->assertJsonValidationErrors(['total_approved_budget']);
        $this->patchJson($project, ['total_approved_budget' => '1,23.456'])->assertStatus(422)->assertJsonValidationErrors(['total_approved_budget']);
        $this->patchJson($project, ['total_approved_budget' => 'three million'])->assertStatus(422)->assertJsonValidationErrors(['total_approved_budget']);
        $this->assertSame('3178376.00', (string) $this->project->fresh()->total_approved_budget);
    }

    public function test_expense_purchase_request_and_budget_request_amounts_accept_commas(): void
    {
        $this->actingAs($this->assistant);
        $id = $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['amount' => '₱ 12,500.50']))
            ->assertCreated()->json('expense.id');
        $this->assertSame('12500.50', (string) Expense::findOrFail($id)->amount);

        $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['amount' => '12,500.505']))
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'Enter a number with at most two decimals.');

        $this->postJson("/api/projects/{$this->project->id}/purchase-requests", [
            'lib_category_id' => $this->lineItem->id, 'description' => 'Reagents', 'quantity' => 1, 'estimated_amount' => '3,500.00',
        ])->assertCreated();
        $this->assertDatabaseHas('purchase_requests', ['description' => 'Reagents', 'estimated_amount' => 3500]);

        $other = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'MOOE', 'line_item_name' => 'Travel', 'allocated_amount' => 100000,
        ]));
        $this->actingAs($this->projectLeader)->postJson("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'realignment', 'from_lib_category_id' => $this->lineItem->id, 'to_lib_category_id' => $other->id,
            'amount' => '10,000.00', 'justification' => 'Move to travel.',
        ])->assertCreated()->assertJsonPath('budget_request.amount', 10000);
    }

    public function test_dates_outside_2000_to_2100_are_refused_with_one_message(): void
    {
        $this->actingAs($this->programLeader);
        $project = "/api/program/projects/{$this->project->id}";

        foreach (['222222-12-15', '1999-12-31', '2101-01-01'] as $bad) {
            $this->patchJson($project, ['start_date' => $bad])->assertStatus(422)->assertJsonPath('errors.start_date', [DateInRange::MESSAGE]);
            $this->patchJson($project, ['moa_date' => $bad])->assertStatus(422)->assertJsonPath('errors.moa_date', [DateInRange::MESSAGE]);
            $this->postJson("{$project}/tranches", ['amount' => 1000, 'planned_release_date' => $bad])->assertStatus(422)
                ->assertJsonPath('errors.planned_release_date', [DateInRange::MESSAGE]);
        }
        $this->assertSame(0, ProjectTranche::count());

        // The edges are fine, and the existing rules still apply.
        $this->patchJson($project, ['start_date' => '2000-01-01', 'end_date' => '2100-12-31'])->assertOk();
        $this->patchJson($project, ['start_date' => '2026-06-01', 'end_date' => '2026-01-01'])->assertStatus(422)->assertJsonValidationErrors(['end_date']);

        $this->actingAs($this->assistant)->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['expense_date' => '222222-12-15']))
            ->assertStatus(422)->assertJsonPath('errors.expense_date', [DateInRange::MESSAGE]);

        $this->actingAs($this->programLeader)->getJson('/api/program/audit?from=222222-01-01')->assertStatus(422)
            ->assertJsonPath('errors.from', [DateInRange::MESSAGE]);
        $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/transactions?to=1999-12-31")->assertStatus(422)
            ->assertJsonPath('errors.to', [DateInRange::MESSAGE]);
    }

    public function test_the_rule_reads_dates_and_months(): void
    {
        $rule = new DateInRange();
        foreach (['2000-01-01', '2026-11-20', '2100-12-31', '2026-11'] as $ok) {
            $this->assertTrue($rule->passes('d', $ok), $ok);
        }
        foreach (['222222-12-15', '1999-12-31', '2101-01', '0222-12-15'] as $bad) {
            $this->assertFalse($rule->passes('d', $bad), $bad);
        }
        // Not a date at all: left to the 'date' rule.
        $this->assertTrue($rule->passes('d', 'next week'));
    }
}

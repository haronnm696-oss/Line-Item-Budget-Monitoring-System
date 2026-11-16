<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\User;
use App\Services\ProjectFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Expense workflow: draft -> submitted -> endorsed (Approved by Project
 * Leader) -> verified, or returned. Role checks (403), maker != checker
 * (403), allowed transitions (422), one audit entry per step and one
 * notification per recipient per hand-off.
 */
class ExpenseWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    public function test_full_path_from_draft_to_verified(): void
    {
        $audit = AuditTrail::count();

        // Draft (one "created" entry, nobody notified).
        $id = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload())
            ->assertCreated()
            ->assertJsonPath('expense.workflow_status', 'draft')
            ->assertJsonPath('expense.status', 'pending')
            ->json('expense.id');
        $this->assertAuditDelta($audit, 'created', $id);
        $this->assertSame(0, DB::table('notifications')->count());

        $expense = Expense::findOrFail($id);
        $this->receiptFor($expense, $this->assistant);

        // Submit: Project Leader notified.
        $audit = AuditTrail::count();
        $this->postJson("/api/expenses/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'submitted')
            ->assertJsonPath('expense.workflow_label', 'Submitted');
        $this->assertAuditDelta($audit, 'submitted', $id);
        $this->assertNotifiedOnce($this->projectLeader, 'submitted', $id);
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertNotNull($expense->fresh()->submitted_at);

        // Endorse: each Finance/Admin of the project notified once.
        $audit = AuditTrail::count();
        $this->actingAs($this->projectLeader)
            ->postJson("/api/expenses/{$id}/endorse")
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'endorsed')
            ->assertJsonPath('expense.workflow_label', 'Approved by Project Leader')
            ->assertJsonPath('expense.pl_reviewed_by.id', $this->projectLeader->id);
        $this->assertAuditDelta($audit, 'endorsed', $id);
        $this->assertNotifiedOnce($this->finance, 'endorsed', $id);
        $this->assertNotifiedOnce($this->finance2, 'endorsed', $id);
        $this->assertSame(3, DB::table('notifications')->count());

        // Not spent yet.
        $this->assertSame(0.0, app(ProjectFinancialService::class)->spent($this->project));

        // Verify: the recorder notified; now it counts as spent.
        $audit = AuditTrail::count();
        $this->actingAs($this->finance)
            ->postJson("/api/expenses/{$id}/verify")
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'verified')
            ->assertJsonPath('expense.status', 'verified')
            ->assertJsonPath('expense.verified_by.id', $this->finance->id);
        $this->assertAuditDelta($audit, 'verified', $id);
        $this->assertNotifiedOnce($this->assistant, 'verified', $id);
        $this->assertSame(4, DB::table('notifications')->count());
        $this->assertSame(2500.5, app(ProjectFinancialService::class)->spent($this->project));
    }

    public function test_submit_without_a_receipt_is_refused(): void
    {
        $expense = $this->expenseIn('draft', null, false);
        $audit = AuditTrail::count();

        $this->actingAs($this->assistant)
            ->postJson("/api/expenses/{$expense->id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);

        $this->assertSame('draft', $expense->fresh()->workflow_status);
        $this->assertSame($audit, AuditTrail::count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_project_leader_return_needs_a_reason_and_notifies_the_recorder(): void
    {
        $expense = $this->expenseIn('submitted');
        $this->actingAs($this->projectLeader);

        $this->postJson("/api/expenses/{$expense->id}/return", ['reason' => '   '])->assertStatus(422);
        $this->assertSame('submitted', $expense->fresh()->workflow_status);

        $audit = AuditTrail::count();
        $this->postJson("/api/expenses/{$expense->id}/return", ['reason' => 'Receipt is unreadable.'])
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'returned')
            ->assertJsonPath('expense.status', 'rejected')
            ->assertJsonPath('expense.return_reason', 'Receipt is unreadable.');

        $entry = $this->assertAuditDelta($audit, 'returned', $expense->id);
        $this->assertSame('Receipt is unreadable.', $entry->reason);
        $this->assertNotifiedOnce($this->assistant, 'returned', $expense->id);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_finance_reject_needs_a_reason_and_sends_the_expense_back(): void
    {
        $expense = $this->expenseIn('endorsed', null, true, ['pl_reviewed_by' => null]);
        $this->actingAs($this->finance);

        $this->postJson("/api/expenses/{$expense->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $audit = AuditTrail::count();
        $this->postJson("/api/expenses/{$expense->id}/reject", ['reason' => 'Amount does not match the receipt.'])
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'returned')
            ->assertJsonPath('expense.status', 'rejected');

        $entry = $this->assertAuditDelta($audit, 'rejected', $expense->id);
        $this->assertSame('Amount does not match the receipt.', $entry->reason);
        $this->assertNotifiedOnce($this->assistant, 'rejected', $expense->id);
        $this->assertSame(0.0, app(ProjectFinancialService::class)->spent($this->project));
    }

    public function test_a_returned_expense_can_be_edited_and_resubmitted(): void
    {
        $expense = $this->expenseIn('returned', null, true, ['return_reason' => 'Wrong amount.', 'status' => 'rejected']);
        $this->actingAs($this->assistant);

        $audit = AuditTrail::count();
        $this->patchJson("/api/expenses/{$expense->id}", ['amount' => 1300])
            ->assertOk()
            ->assertJsonPath('expense.amount', 1300);
        $this->assertAuditDelta($audit, 'updated', $expense->id);

        $this->postJson("/api/expenses/{$expense->id}/submit")
            ->assertOk()
            ->assertJsonPath('expense.workflow_status', 'submitted')
            ->assertJsonPath('expense.return_reason', null);
    }

    public function test_each_step_is_limited_to_its_role_on_the_project(): void
    {
        // RA / FIN record expenses; so may the Project Leader (their own,
        // see ProjectLeaderPagesTest). Researchers and the Program Leader may not.
        foreach ([$this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)
                ->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload())
                ->assertForbidden();
        }
        $this->actingAs($this->finance)
            ->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload())
            ->assertCreated();

        $submitted = $this->expenseIn('submitted');
        $endorsed = $this->expenseIn('endorsed');

        // Only the Project Leader endorses or returns.
        foreach ([$this->assistant, $this->finance, $this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)->postJson("/api/expenses/{$submitted->id}/endorse")->assertForbidden();
            $this->actingAs($user)->postJson("/api/expenses/{$submitted->id}/return", ['reason' => 'No.'])->assertForbidden();
        }

        // Only Finance/Admin verifies or rejects.
        foreach ([$this->assistant, $this->projectLeader, $this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)->postJson("/api/expenses/{$endorsed->id}/verify")->assertForbidden();
            $this->actingAs($user)->postJson("/api/expenses/{$endorsed->id}/reject", ['reason' => 'No.'])->assertForbidden();
        }

        // A Project Leader cannot submit someone else's draft.
        $draft = $this->expenseIn('draft');
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$draft->id}/submit")->assertForbidden();

        $this->assertSame('submitted', $submitted->fresh()->workflow_status);
        $this->assertSame('endorsed', $endorsed->fresh()->workflow_status);
        $this->assertSame('draft', $draft->fresh()->workflow_status);
    }

    public function test_maker_cannot_be_checker_at_any_step(): void
    {
        // Finance/Admin recorded it: cannot verify or reject it.
        $own = $this->expenseIn('endorsed', $this->finance);
        $this->actingAs($this->finance)
            ->postJson("/api/expenses/{$own->id}/verify")
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot verify or reject an expense you recorded.');
        $this->postJson("/api/expenses/{$own->id}/reject", ['reason' => 'x'])->assertForbidden();
        // The other Finance/Admin can.
        $this->actingAs($this->finance2)->postJson("/api/expenses/{$own->id}/verify")->assertOk();

        // The person who approved it as Project Leader cannot also verify it.
        $endorsedBy = $this->expenseIn('endorsed', null, true, ['pl_reviewed_by' => $this->finance->id]);
        $this->actingAs($this->finance)
            ->postJson("/api/expenses/{$endorsedBy->id}/verify")
            ->assertForbidden()
            ->assertJsonPath('message', 'You approved this expense as Project Leader; another person must verify it.');

        // A Project Leader cannot approve or return an expense they recorded.
        $byLeader = $this->expenseIn('submitted', $this->projectLeader);
        $this->actingAs($this->projectLeader)
            ->postJson("/api/expenses/{$byLeader->id}/endorse")
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot approve or return an expense you recorded.');
        $this->postJson("/api/expenses/{$byLeader->id}/return", ['reason' => 'x'])->assertForbidden();

        $this->assertSame('endorsed', $endorsedBy->fresh()->workflow_status);
        $this->assertSame('submitted', $byLeader->fresh()->workflow_status);
    }

    public function test_invalid_transitions_are_refused_with_422(): void
    {
        $draft = $this->expenseIn('draft');
        $submitted = $this->expenseIn('submitted');
        $verified = $this->expenseIn('verified');
        $audit = AuditTrail::count();

        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$draft->id}/endorse")->assertStatus(422);
        $this->actingAs($this->finance)->postJson("/api/expenses/{$submitted->id}/verify")->assertStatus(422);
        $this->actingAs($this->finance)->postJson("/api/expenses/{$draft->id}/verify")->assertStatus(422);
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$submitted->id}/submit")->assertStatus(422);
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$verified->id}/submit")->assertStatus(422);

        // No edits or deletes after verification (or while under review).
        $this->actingAs($this->assistant)
            ->patchJson("/api/expenses/{$verified->id}", ['amount' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.workflow_status.0', 'This expense cannot be edited while it is verified.');
        $this->patchJson("/api/expenses/{$submitted->id}", ['amount' => 1])->assertStatus(422);
        $this->deleteJson("/api/expenses/{$verified->id}")->assertStatus(422);

        $this->assertSame($audit, AuditTrail::count());
        $this->assertSame(1200.0, (float) $verified->fresh()->amount);
    }

    public function test_an_encoder_deletes_only_their_own_draft_or_returned_expense(): void
    {
        $mine = $this->expenseIn('draft');
        $returned = $this->expenseIn('returned');
        $theirs = $this->expenseIn('draft', $this->finance);

        $this->actingAs($this->assistant)->deleteJson("/api/expenses/{$theirs->id}")->assertForbidden();

        $audit = AuditTrail::count();
        $this->deleteJson("/api/expenses/{$mine->id}")->assertOk();
        $this->assertAuditDelta($audit, 'deleted', $mine->id);
        $this->deleteJson("/api/expenses/{$returned->id}")->assertOk();

        // Soft deleted: the row stays for history but leaves the list.
        $this->assertSoftDeleted('expenses', ['id' => $mine->id]);
        $ids = array_column($this->getJson("/api/projects/{$this->project->id}/expenses")->assertOk()->json('expenses'), 'id');
        $this->assertSame([$theirs->id], $ids);
    }

    public function test_list_filters_by_stage_and_is_project_scoped(): void
    {
        $draft = $this->expenseIn('draft');
        $submitted = $this->expenseIn('submitted');

        $rows = $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/expenses?workflow_status=submitted")
            ->assertOk()
            ->json('expenses');
        $this->assertSame([$submitted->id], array_column($rows, 'id'));
        $this->assertSame(1, $rows[0]['receipts_count']);

        $this->getJson("/api/projects/{$this->project->id}/expenses?workflow_status=paid")->assertStatus(422);

        // Program Leader sees every project; an outsider sees none of this one.
        $this->actingAs($this->programLeader)->getJson("/api/projects/{$this->project->id}/expenses")->assertOk();
        $this->actingAs($this->outsider);
        $this->getJson("/api/projects/{$this->project->id}/expenses")->assertForbidden();
        $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload())->assertForbidden();
        $this->patchJson("/api/expenses/{$draft->id}", ['amount' => 5])->assertForbidden();
        $this->deleteJson("/api/expenses/{$draft->id}")->assertForbidden();
        foreach (['submit', 'endorse', 'verify'] as $step) {
            $this->postJson("/api/expenses/{$submitted->id}/{$step}")->assertForbidden();
        }
        $this->postJson("/api/expenses/{$submitted->id}/reject", ['reason' => 'x'])->assertForbidden();

        $this->assertSame('submitted', $submitted->fresh()->workflow_status);
        $this->assertNull($draft->fresh()->deleted_at);
    }

    public function test_a_line_item_of_another_project_is_refused(): void
    {
        $foreign = \App\Models\LibCategory::create([
            'project_id' => $this->otherProject->id, 'category_type' => 'MOOE',
            'line_item_name' => 'Other supplies', 'allocated_amount' => 1000,
        ]);

        $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['lib_category_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lib_category_id']);
        $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['amount' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_backfill_maps_the_old_status_to_a_workflow_stage(): void
    {
        $base = [
            'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id,
            'recorded_by' => $this->assistant->id, 'description' => 'Legacy', 'amount' => 100,
            'expense_date' => '2026-01-10', 'created_at' => '2026-01-10 08:00:00', 'updated_at' => '2026-01-10 08:00:00',
        ];
        // Raw inserts: legacy rows have no workflow_status.
        $pending = DB::table('expenses')->insertGetId($base + ['status' => 'pending']);
        $verified = DB::table('expenses')->insertGetId($base + ['status' => 'verified']);
        $rejected = DB::table('expenses')->insertGetId($base + ['status' => 'rejected', 'remarks' => 'Missing OR.']);
        $already = DB::table('expenses')->insertGetId($base + ['status' => 'pending', 'workflow_status' => 'draft']);

        $migration = require database_path('migrations/2026_11_16_000001_add_workflow_to_expenses_table.php');
        $migration->backfill();
        $migration->backfill(); // safe to rerun

        $row = fn ($id) => DB::table('expenses')->find($id);
        $this->assertSame('submitted', $row($pending)->workflow_status);
        $this->assertSame('pending', $row($pending)->status);
        $this->assertNotNull($row($pending)->submitted_at);
        $this->assertSame('verified', $row($verified)->workflow_status);
        $this->assertSame('verified', $row($verified)->status);
        $this->assertSame('returned', $row($rejected)->workflow_status);
        $this->assertSame('Missing OR.', $row($rejected)->return_reason);
        $this->assertSame('draft', $row($already)->workflow_status);
    }

    public function test_the_audit_trail_names_each_step(): void
    {
        $expense = $this->expenseIn('draft');
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$expense->id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$expense->id}/return", ['reason' => 'Blurry receipt.'])->assertOk();
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$expense->id}/submit")->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$expense->id}/endorse")->assertOk();

        $entries = collect($this->actingAs($this->programLeader)->getJson('/api/program/audit')->assertOk()->json('entries'));
        $labels = $entries->pluck('action.label')->all();

        $this->assertSame(['Expense approved by Project Leader', 'Expense submitted', 'Expense returned', 'Expense submitted'], $labels);
        $this->assertSame('Blurry receipt.', $entries[2]['reason']);
        $this->assertSame('danger', $entries[2]['action']['tone']);
        $this->assertSame('success', $entries[0]['action']['tone']);
    }

    private function assertAuditDelta(int $before, string $action, int $expenseId): AuditTrail
    {
        $this->assertSame($before + 1, AuditTrail::count(), "exactly one audit entry for {$action}");
        $entry = AuditTrail::query()->latest('id')->firstOrFail();
        $this->assertSame($action, $entry->action);
        $this->assertSame('Expense', $entry->model_type);
        $this->assertSame($expenseId, (int) $entry->model_id);
        $this->assertSame($this->project->id, (int) $entry->project_id);

        return $entry;
    }

    private function assertNotifiedOnce(User $user, string $event, int $expenseId): void
    {
        $rows = $user->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === $event);
        $this->assertCount(1, $rows, "{$user->name} gets one {$event} notification");
        $data = $rows->first()->data;
        $this->assertSame($expenseId, $data['expense_id']);
        $this->assertSame("/expenses?project={$this->project->id}&expense={$expenseId}", $data['link']);
    }
}

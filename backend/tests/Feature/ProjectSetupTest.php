<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Services\AuditLogger;
use App\Services\ProjectSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Program Leader project set-up: New project, details and Project Leader,
 * Line-Item Budget and planned tranches (ProjectSetupService), plus the
 * available-balance check on expense submit and the "No tranches set yet"
 * state. Fixture project: total budget 1,000,000; "Supplies" MOOE 500,000.
 */
class ProjectSetupTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    public function test_only_the_program_leader_can_set_up_projects(): void
    {
        $item = $this->lineItem;
        $tranche = $this->tranche(100000);
        $p = $this->project->id;
        $calls = [
            ['GET', '/api/program/project-options', []],
            ['POST', '/api/program/projects', $this->newProject()],
            ['PATCH', "/api/program/projects/{$p}", ['funder_reference' => 'X']],
            ['POST', "/api/program/projects/{$p}/line-items", ['category_type' => 'PS', 'line_item_name' => 'Honoraria', 'allocated_amount' => 1]],
            ['PATCH', "/api/program/line-items/{$item->id}", ['line_item_name' => 'X']],
            ['DELETE', "/api/program/line-items/{$item->id}", []],
            ['POST', "/api/program/projects/{$p}/tranches", ['amount' => 1, 'planned_release_date' => '2026-12-01']],
            ['PATCH', "/api/program/tranches/{$tranche->id}", ['amount' => 2]],
            ['DELETE', "/api/program/tranches/{$tranche->id}", []],
        ];

        $audit = AuditTrail::count();
        foreach ([$this->projectLeader, $this->assistant, $this->finance, $this->researcher, $this->outsider] as $user) {
            $this->actingAs($user);
            foreach ($calls as [$method, $uri, $body]) {
                $this->json($method, $uri, $body)->assertForbidden();
            }
        }
        $this->assertSame($audit, AuditTrail::count());
        $this->assertSame(0, Project::where('code', 'RP-2026-010')->count()); // nothing created
        $this->actingAs($this->programLeader)->getJson('/api/program/project-options')->assertOk()
            ->assertJsonPath('leaders.0.id', $this->projectLeader->id);
    }

    public function test_new_project_with_its_leader_in_one_audit_entry(): void
    {
        $audit = AuditTrail::count();
        $id = $this->actingAs($this->programLeader)->postJson('/api/program/projects', $this->newProject())
            ->assertCreated()->json('project.id');

        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame(['created', 'Project', $id], [$entry->action, $entry->model_type, (int) $entry->model_id]);
        $this->assertSame($this->projectLeader->id, $entry->new_values['project_leader_id']);

        // The leader has the project at once, on the pages of their role.
        $row = collect($this->actingAs($this->projectLeader)->getJson('/api/projects')->json())->firstWhere('id', $id);
        $this->assertSame(['project-leader'], $row['my_roles']);
        $this->getJson("/api/projects/{$id}/leader-overview")->assertOk()
            ->assertJsonPath('alerts.next_tranche', null)
            ->assertJsonPath('alerts.tranche_count', 0); // "No tranches set yet", not "All released"

        // Validation: unique code, period order, leader must be a Project Leader account.
        $this->actingAs($this->programLeader);
        $this->postJson('/api/program/projects', $this->newProject())->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Another project already uses this code.');
        $this->postJson('/api/program/projects', $this->newProject(['code' => 'RP-2026-011', 'end_date' => '2025-01-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->postJson('/api/program/projects', $this->newProject(['code' => 'RP-2026-012', 'project_leader_id' => $this->researcher->id]))
            ->assertStatus(422)->assertJsonPath('errors.project_leader_id.0', 'Choose an active Project Leader account.');
        $this->assertSame($audit + 1, AuditTrail::count());
    }

    public function test_edit_details_and_change_the_project_leader(): void
    {
        $newLeader = AuditLogger::muted(fn () => $this->makePerson('project-leader'));
        $audit = AuditTrail::count();
        $this->actingAs($this->programLeader)->patchJson("/api/program/projects/{$this->project->id}", [
            'funder_reference' => 'PCAARRD-2026-1', 'start_date' => '2026-02-01', 'end_date' => '2027-01-31',
            'project_leader_id' => $newLeader->id,
        ])->assertOk();

        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame('updated', $entry->action);
        $this->assertSame($this->projectLeader->id, $entry->old_values['project_leader_id']);
        $this->assertSame($newLeader->id, $entry->new_values['project_leader_id']);
        $this->assertSame('PCAARRD-2026-1', $this->project->fresh()->funder_reference);
        $this->assertTrue($newLeader->hasRoleOn($this->project, 'project-leader'));
        $this->assertFalse($this->projectLeader->fresh()->hasRoleOn($this->project, 'project-leader'));
        $this->actingAs($this->projectLeader->fresh())->getJson("/api/projects/{$this->project->id}/leader-overview")->assertForbidden();

        // The same values again: nothing logged.
        $this->actingAs($this->programLeader)->patchJson("/api/program/projects/{$this->project->id}", ['funder_reference' => 'PCAARRD-2026-1'])->assertOk();
        $this->assertSame($audit + 1, AuditTrail::count());

        // The total budget cannot go below the line items.
        $this->patchJson("/api/program/projects/{$this->project->id}", ['total_approved_budget' => 400000])->assertStatus(422)
            ->assertJsonValidationErrors(['total_approved_budget']);
    }

    public function test_line_items_never_exceed_the_total_budget_and_each_change_is_one_entry(): void
    {
        $this->actingAs($this->programLeader);
        $url = "/api/program/projects/{$this->project->id}/line-items";

        $audit = AuditTrail::count();
        $id = $this->postJson($url, ['category_type' => 'PS', 'line_item_name' => 'Honoraria', 'allocated_amount' => 300000])
            ->assertCreated()->json('line_item.id');
        $this->assertStep($audit, 'created', 'LibCategory');

        // 500,000 + 300,000 allocated; 200,000 left.
        $this->postJson($url, ['category_type' => 'MOOE', 'line_item_name' => 'Travel', 'allocated_amount' => 200000.01])->assertStatus(422)
            ->assertJsonPath('errors.allocated_amount.0', 'Line items would total ₱1,000,000.01, more than the total budget of ₱1,000,000.00. ₱200,000.00 is left to allocate.');
        $this->postJson($url, ['category_type' => 'PS', 'line_item_name' => 'honoraria', 'allocated_amount' => 1])->assertStatus(422)
            ->assertJsonValidationErrors(['line_item_name']); // same name in PS
        $this->postJson($url, ['category_type' => 'CO', 'line_item_name' => 'Equipment', 'allocated_amount' => 1])->assertStatus(422);
        $this->postJson($url, ['category_type' => 'MOOE', 'line_item_name' => 'Travel', 'allocated_amount' => -1])->assertStatus(422);
        $this->postJson($url, ['category_type' => 'MOOE', 'line_item_name' => 'Travel', 'allocated_amount' => '1.234'])->assertStatus(422);
        $this->assertSame($audit + 1, AuditTrail::count());

        $audit = AuditTrail::count();
        $this->patchJson("/api/program/line-items/{$id}", ['allocated_amount' => 500000.01])->assertStatus(422);
        $this->patchJson("/api/program/line-items/{$id}", ['allocated_amount' => 500000, 'line_item_name' => 'Project staff honoraria'])->assertOk();
        $this->assertStep($audit, 'updated', 'LibCategory');

        $audit = AuditTrail::count();
        $this->deleteJson("/api/program/line-items/{$id}")->assertOk();
        $this->assertStep($audit, 'deleted', 'LibCategory');
        $this->assertNull(LibCategory::find($id));
    }

    public function test_a_line_item_with_expenses_is_locked(): void
    {
        $this->actingAs($this->programLeader);
        $item = $this->lineItem;

        // A draft is enough to block removal and a category change.
        $this->expenseIn('draft', null, true, ['amount' => 1000]);
        $this->deleteJson("/api/program/line-items/{$item->id}")->assertStatus(422)->assertJsonValidationErrors(['line_item']);
        $this->patchJson("/api/program/line-items/{$item->id}", ['category_type' => 'PS'])->assertStatus(422)->assertJsonValidationErrors(['category_type']);

        // Not below spent + pending (here: 40,000 submitted).
        $this->expenseIn('submitted', null, true, ['amount' => 40000]);
        $this->patchJson("/api/program/line-items/{$item->id}", ['allocated_amount' => 39999.99])->assertStatus(422)
            ->assertJsonPath('errors.allocated_amount.0', 'The allocated amount cannot be less than what is already spent and pending on this line item (₱40,000.00).');
        $this->patchJson("/api/program/line-items/{$item->id}", ['allocated_amount' => 40000])->assertOk();

        // After the first verified expense: amounts only through a realignment request.
        $this->expenseIn('verified', null, true, ['amount' => 100]);
        $this->patchJson("/api/program/line-items/{$item->id}", ['allocated_amount' => 60000])->assertStatus(422)
            ->assertJsonPath('errors.allocated_amount.0', ProjectSetupService::REALIGNMENT_ONLY);
        $this->patchJson("/api/program/line-items/{$item->id}", ['line_item_name' => 'Laboratory supplies'])->assertOk();

        $setup = $this->getJson("/api/program/projects/{$this->project->id}")->assertOk()->json('setup');
        $this->assertTrue($setup['allocations_locked']);
        $this->assertSame([$item->id], $setup['used_line_items']);
    }

    public function test_tranches_change_only_while_not_released_and_not_requested(): void
    {
        $this->actingAs($this->programLeader);
        $url = "/api/program/projects/{$this->project->id}/tranches";

        $audit = AuditTrail::count();
        $id = $this->postJson($url, ['amount' => 400000, 'planned_release_date' => '2026-12-01'])->assertCreated()
            ->assertJsonPath('tranche.name', 'Release 1')->assertJsonPath('tranche.sequence', 1)->json('tranche.id');
        $this->assertStep($audit, 'created', 'ProjectTranche');
        $this->postJson($url, ['amount' => 600000.01, 'planned_release_date' => '2027-01-01'])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $audit = AuditTrail::count();
        $this->patchJson("/api/program/tranches/{$id}", ['amount' => 450000, 'planned_release_date' => '2026-12-15'])->assertOk();
        $this->assertStep($audit, 'updated', 'ProjectTranche');

        // A pending fund release request locks it; a released one stays locked.
        $request = AuditLogger::muted(fn () => ApprovalRequest::create([
            'project_id' => $this->project->id, 'requested_by' => $this->projectLeader->id, 'type' => 'disbursement',
            'title' => 'Fund release', 'amount' => 450000, 'project_tranche_id' => $id, 'status' => 'pending', 'justification' => 'Needed.',
        ]));
        $this->patchJson("/api/program/tranches/{$id}", ['amount' => 1])->assertStatus(422)->assertJsonValidationErrors(['tranche']);
        $this->deleteJson("/api/program/tranches/{$id}")->assertStatus(422);
        $this->postJson("/api/program/approvals/{$request->id}/decision", ['decision' => 'approved'])->assertOk();
        $this->assertSame('released', ProjectTranche::find($id)->status);
        $this->patchJson("/api/program/tranches/{$id}", ['amount' => 1])->assertStatus(422)
            ->assertJsonPath('errors.tranche.0', 'The funds for this release are already released, so it cannot be changed.');
        $this->deleteJson("/api/program/tranches/{$id}")->assertStatus(422);

        // The funder total is the fixture's funder line items (500,000): 50,000 is left.
        $second = $this->postJson($url, ['amount' => 50000, 'planned_release_date' => '2027-03-01'])->assertCreated()
            ->assertJsonPath('tranche.name', 'Release 2')->json('tranche.id');
        $audit = AuditTrail::count();
        $this->deleteJson("/api/program/tranches/{$second}")->assertOk();
        $this->assertStep($audit, 'deleted', 'ProjectTranche');

        $setup = $this->getJson("/api/program/projects/{$this->project->id}")->json('setup');
        $this->assertSame([], $setup['editable_tranches']);
    }

    public function test_an_expense_above_the_available_balance_cannot_be_submitted(): void
    {
        // Supplies: 500,000 allocated; 300,000 spent; 150,000 pending -> 50,000 available.
        $this->expenseIn('verified', null, true, ['amount' => 300000]);
        $this->expenseIn('submitted', null, true, ['amount' => 150000]);
        $tooMuch = $this->expenseIn('draft', null, true, ['amount' => 50000.01]);
        $exact = $this->expenseIn('draft', null, true, ['amount' => 50000]);

        $audit = AuditTrail::count();
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$tooMuch->id}/submit")->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'The amount (₱50,000.01) is more than the ₱50,000.00 available on Supplies. Lower the amount or ask the Project Leader for a realignment.');
        $this->assertSame('draft', $tooMuch->fresh()->workflow_status);
        $this->assertSame($audit, AuditTrail::count());

        $this->postJson("/api/expenses/{$exact->id}/submit")->assertOk();
        // Now nothing is left: even the smallest amount is refused.
        $this->patchJson("/api/expenses/{$tooMuch->id}", ['amount' => 0.01])->assertOk();
        $this->postJson("/api/expenses/{$tooMuch->id}/submit")->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_the_leader_sees_no_tranches_set_yet_until_one_is_added(): void
    {
        $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/leader-overview")
            ->assertOk()->assertJsonPath('alerts.tranche_count', 0)->assertJsonPath('alerts.next_tranche', null);

        $this->actingAs($this->programLeader)->postJson("/api/program/projects/{$this->project->id}/tranches", ['amount' => 1000, 'planned_release_date' => '2026-12-01'])->assertCreated();
        $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/leader-overview")
            ->assertJsonPath('alerts.tranche_count', 1)->assertJsonPath('alerts.next_tranche.amount', 1000);
    }

    // ---------------------------------------------------------------- helpers

    private function newProject(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Seaweed Value Chain', 'code' => 'RP-2026-010',
            'funding_agency_id' => $this->project->funding_agency_id, 'funder_reference' => 'MOST-2026-77',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_approved_budget' => 750000,
            'project_leader_id' => $this->projectLeader->id,
        ], $overrides);
    }

    private function tranche(float $amount): ProjectTranche
    {
        return AuditLogger::muted(fn () => ProjectTranche::create([
            'project_id' => $this->project->id, 'sequence' => 1, 'name' => 'First tranche', 'amount' => $amount,
            'status' => 'upcoming', 'planned_release_date' => '2026-12-01',
        ]));
    }

    private function assertStep(int $before, string $action, string $type): void
    {
        $this->assertSame($before + 1, AuditTrail::count(), "one audit entry for {$action}");
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame([$action, $type, $this->project->id], [$entry->action, $entry->model_type, (int) $entry->project_id]);
    }
}

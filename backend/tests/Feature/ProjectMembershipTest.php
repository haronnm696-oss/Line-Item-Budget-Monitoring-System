<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * The "my projects" resolver (User::rolesOn / hasRoleOn / projectIdsAs and
 * my_roles on GET /api/projects) with the production case: one person with
 * two project_user rows on the same project. Every page must agree, and the
 * Program Leader's edit or removal merges the rows.
 */
class ProjectMembershipTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        // Legacy data: the leader also holds an older Researcher row on the project.
        $this->leader = AuditLogger::muted(function () {
            $user = $this->makePerson('project-leader');
            $this->assignTo($user, $this->project, 'researcher');
            $this->assignTo($user, $this->project, 'project-leader');

            return $user;
        });
    }

    public function test_a_person_with_two_rows_gets_the_project_on_every_leader_page(): void
    {
        $this->assertSame(['researcher', 'project-leader'], $this->leader->rolesOn($this->project));
        $this->assertSame('project-leader', $this->leader->roleOn($this->project)); // their account role wins for display
        $this->assertTrue($this->leader->hasRoleOn($this->project, 'project-leader'));
        $this->assertSame([$this->project->id], $this->leader->projectIdsAs('project-leader'));

        $this->actingAs($this->leader);
        $row = collect($this->getJson('/api/projects')->assertOk()->json())->firstWhere('id', $this->project->id);
        $this->assertSame(['researcher', 'project-leader'], $row['my_roles']);
        $this->assertSame('project-leader', $row['my_role']);

        $p = $this->project->id;
        foreach (['leader-overview', 'leader-badges', 'review-queue', 'budget-requests', 'transactions', 'budget-summary'] as $page) {
            $this->getJson("/api/projects/{$p}/{$page}")->assertOk();
        }
        $this->postJson("/api/projects/{$p}/budget-requests", [
            'type' => 'liquidation', 'title' => 'First tranche', 'amount' => 100, 'justification' => 'Liquidation.',
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('l.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

        // Notification recipients use the same rule.
        $expense = $this->expenseIn('draft');
        $this->actingAs($this->assistant)->postJson("/api/expenses/{$expense->id}/submit")->assertOk();
        $this->assertSame(1, $this->leader->notifications()->count());
    }

    public function test_the_program_leaders_role_change_merges_the_rows_into_one(): void
    {
        $audit = AuditTrail::count();
        $this->actingAs($this->programLeader)
            ->patchJson("/api/program/assignments/{$this->leader->id}/{$this->project->id}", ['role' => 'project-leader'])
            ->assertOk();

        $rows = ProjectUser::where('project_id', $this->project->id)->where('user_id', $this->leader->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['project-leader'], $this->leader->fresh()->rolesOn($this->project));
        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame('assignment_updated', $entry->action);
        $this->assertSame(['researcher', 'project-leader'], $entry->old_values['roles']);

        // Once merged, the same role again changes nothing.
        $this->patchJson("/api/program/assignments/{$this->leader->id}/{$this->project->id}", ['role' => 'project-leader'])->assertOk();
        $this->assertSame($audit + 1, AuditTrail::count());
    }

    public function test_removal_takes_every_row_and_all_access_at_once(): void
    {
        $audit = AuditTrail::count();
        $this->actingAs($this->programLeader)
            ->deleteJson("/api/program/assignments/{$this->leader->id}/{$this->project->id}", ['reason' => 'Left the project.'])
            ->assertOk();

        $this->assertSame(0, ProjectUser::where('project_id', $this->project->id)->where('user_id', $this->leader->id)->count());
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->actingAs($this->leader->fresh());
        $this->getJson('/api/projects')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/projects/{$this->project->id}/leader-overview")->assertForbidden();
        $this->getJson("/api/projects/{$this->project->id}/transactions")->assertForbidden();
    }
}

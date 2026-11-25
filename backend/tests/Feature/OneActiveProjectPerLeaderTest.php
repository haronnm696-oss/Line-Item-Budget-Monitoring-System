<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * A Project Leader leads only ONE active project (planning or ongoing) at a
 * time; completed projects do not count. One shared check
 * (ProjectLeaderRule) in every place a leader is set. $this->projectLeader
 * leads the active WF-001; WF-002 is active with no leader.
 */
class OneActiveProjectPerLeaderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    private function refusal(): string
    {
        return "{$this->projectLeader->name} already leads WF-001. A Project Leader leads one active project at a time.";
    }

    private function past(): array
    {
        return ['start_date' => now()->subYears(2)->toDateString(), 'end_date' => now()->subMonth()->toDateString()];
    }

    private function future(): array
    {
        return ['start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addYear()->toDateString()];
    }

    private function newProject(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Seaweed Value Chain', 'code' => 'RP-2026-010',
            'funding_agency_id' => $this->project->funding_agency_id,
            'total_approved_budget' => 750000, 'project_leader_id' => $this->projectLeader->id,
        ], $this->future(), $overrides);
    }

    private function makeLed(string $code, array $dates, User $leader): Project
    {
        return AuditLogger::muted(function () use ($code, $dates, $leader) {
            $project = $this->makeProject($code, "Project {$code}");
            $project->update($dates);
            $this->assignTo($leader, $project, 'project-leader');

            return $project;
        });
    }

    // ---------------------------------------------------------------- New project

    public function test_new_project_refuses_a_leader_of_another_active_project(): void
    {
        $this->actingAs($this->programLeader)->postJson('/api/program/projects', $this->newProject())
            ->assertStatus(422)
            ->assertJsonPath('errors.project_leader_id.0', $this->refusal());
        $this->assertNull(Project::where('code', 'RP-2026-010')->first(), 'nothing created');
    }

    public function test_new_completed_project_may_have_a_busy_leader(): void
    {
        $this->actingAs($this->programLeader)->postJson('/api/program/projects', $this->newProject($this->past()))
            ->assertCreated();
    }

    public function test_completed_projects_do_not_count(): void
    {
        $leader = AuditLogger::muted(fn () => $this->makePerson('project-leader'));
        $this->makeLed('OLD-1', $this->past(), $leader);
        $this->makeLed('OLD-2', $this->past(), $leader);

        $this->actingAs($this->programLeader)->postJson('/api/program/projects', $this->newProject(['project_leader_id' => $leader->id]))
            ->assertCreated();
    }

    // ---------------------------------------------------------------- Edit details

    public function test_edit_details_refuses_a_leader_of_another_active_project(): void
    {
        $this->actingAs($this->programLeader)
            ->patchJson("/api/program/projects/{$this->otherProject->id}", ['project_leader_id' => $this->projectLeader->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.project_leader_id.0', $this->refusal());
        $this->assertFalse($this->projectLeader->hasRoleOn($this->otherProject, 'project-leader'));

        // Once WF-001 is completed, they are free.
        AuditLogger::muted(fn () => $this->project->update($this->past()));
        $this->patchJson("/api/program/projects/{$this->otherProject->id}", ['project_leader_id' => $this->projectLeader->id])->assertOk();
        $this->assertTrue($this->projectLeader->hasRoleOn($this->otherProject, 'project-leader'));
    }

    public function test_keeping_the_same_leader_is_not_refused_on_existing_data(): void
    {
        // Older data: the leader also leads WF-002 (not changed automatically).
        AuditLogger::muted(fn () => $this->assignTo($this->projectLeader, $this->otherProject, 'project-leader'));

        $this->actingAs($this->programLeader)->patchJson("/api/program/projects/{$this->otherProject->id}", [
            'project_leader_id' => $this->projectLeader->id, 'funder_reference' => 'REF-1',
        ])->assertOk();
    }

    // ---------------------------------------------------------------- Reactivation

    public function test_reactivating_a_completed_project_is_refused_while_its_leader_leads_another_active_one(): void
    {
        $old = $this->makeLed('OLD-1', $this->past(), $this->projectLeader);

        $this->actingAs($this->programLeader)
            ->patchJson("/api/program/projects/{$old->id}", ['end_date' => now()->addMonths(6)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('errors.end_date.0', $this->refusal());
        $this->assertTrue($old->fresh()->end_date->isPast(), 'still completed');

        // Other edits of the completed project stay allowed.
        $this->patchJson("/api/program/projects/{$old->id}", ['funder_reference' => 'REF-OLD'])->assertOk();

        // With WF-001 completed, the old project can be reactivated.
        AuditLogger::muted(fn () => $this->project->update($this->past()));
        $this->patchJson("/api/program/projects/{$old->id}", ['end_date' => now()->addMonths(6)->toDateString()])->assertOk();
    }

    // ---------------------------------------------------------------- Users and roles

    public function test_assign_to_project_refuses_a_second_active_project(): void
    {
        $this->actingAs($this->programLeader)
            ->postJson('/api/program/assignments', ['user_id' => $this->projectLeader->id, 'project_id' => $this->otherProject->id])
            ->assertStatus(422)
            ->assertJsonPath('message', $this->refusal());

        $old = AuditLogger::muted(function () {
            $project = $this->makeProject('OLD-1', 'Old project');
            $project->update($this->past());

            return $project;
        });
        $this->postJson('/api/program/assignments', ['user_id' => $this->projectLeader->id, 'project_id' => $old->id])
            ->assertCreated();
    }

    private function pendingApplicant(): User
    {
        return User::create([
            'name' => 'Pending Leader', 'first_name' => 'Pending', 'last_name' => 'Leader',
            'email' => 'pending.' . uniqid() . '@msumain.edu.ph', 'password' => Hash::make('password123'),
            'role_id' => null, 'is_active' => false, 'email_verified_at' => now(),
        ]);
    }

    private function approve(User $pending, string $role, array $projects)
    {
        return $this->actingAs($this->programLeader)->postJson("/api/program/registrations/{$pending->id}/decision", [
            'decision' => 'approved', 'role' => $role,
            'assignments' => array_map(fn (Project $project) => ['project_id' => $project->id], $projects),
        ]);
    }

    private function assertStillPending(User $pending): void
    {
        $pending->refresh();
        $this->assertNull($pending->role_id, 'not approved');
        $this->assertSame(0, ProjectUser::where('user_id', $pending->id)->count(), 'nothing saved');
    }

    public function test_registration_review_refuses_two_projects_for_a_project_leader(): void
    {
        Notification::fake();
        $third = AuditLogger::muted(fn () => $this->makeProject('WF-003', 'Third project'));
        $pending = $this->pendingApplicant();

        $this->approve($pending, 'project-leader', [$this->otherProject, $third])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['assignments' => ['A Project Leader leads one project. Keep one project and remove the others.']]);
        $this->assertStillPending($pending);

        // Also when the second project is completed: the drawer takes one project.
        $past = $this->makeLed('WF-004', $this->past(), $this->outsider);
        ProjectUser::where('project_id', $past->id)->delete();
        $this->approve($pending, 'project-leader', [$this->otherProject, $past])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignments');
        $this->assertStillPending($pending);
    }

    public function test_registration_review_refuses_a_project_that_already_has_a_project_leader(): void
    {
        Notification::fake();
        $pending = $this->pendingApplicant();

        // WF-001 is led by $this->projectLeader.
        $response = $this->approve($pending, 'project-leader', [$this->project])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignments.0.project_id']);
        $message = $response->json('errors')['assignments.0.project_id'][0];
        $this->assertStringContainsString('already has a Project Leader (' . $this->projectLeader->name . ')', $message);
        $this->assertStillPending($pending);
    }

    public function test_registration_review_approves_a_project_leader_with_one_free_project(): void
    {
        Notification::fake();
        $pending = $this->pendingApplicant();

        $this->approve($pending, 'project-leader', [$this->otherProject])->assertOk();

        $pending->refresh();
        $this->assertSame('project-leader', $pending->primaryRole->slug);
        $this->assertDatabaseHas('project_user', ['user_id' => $pending->id, 'project_id' => $this->otherProject->id]);
        $this->assertSame(1, ProjectUser::where('user_id', $pending->id)->count());
    }

    public function test_registration_review_still_lets_a_research_assistant_have_two_projects(): void
    {
        Notification::fake();
        $pending = $this->pendingApplicant();

        $this->approve($pending, 'research-assistant', [$this->project, $this->otherProject])->assertOk();

        $this->assertSame(2, ProjectUser::where('user_id', $pending->id)->count());
    }

    public function test_changing_the_account_role_to_project_leader_refuses_two_active_projects(): void
    {
        $person = AuditLogger::muted(fn () => $this->makePerson('researcher'));
        $third = AuditLogger::muted(fn () => $this->makeProject('WF-003', 'Third project'));
        AuditLogger::muted(function () use ($person, $third) {
            $this->assignTo($person, $this->otherProject, 'researcher');
            $this->assignTo($person, $third, 'researcher');
        });

        $this->actingAs($this->programLeader)->patchJson("/api/program/users/{$person->id}/role", ['role' => 'project-leader'])
            ->assertStatus(422)
            ->assertJsonPath('message', "{$person->name} already leads WF-002. A Project Leader leads one active project at a time.");
        $this->assertSame('researcher', $person->fresh()->primaryRole->slug);

        // One of them completed: allowed.
        AuditLogger::muted(fn () => $third->update($this->past()));
        $this->patchJson("/api/program/users/{$person->id}/role", ['role' => 'project-leader'])->assertOk();
    }

    public function test_fix_role_refuses_making_them_leader_of_a_second_active_project(): void
    {
        // A Project Leader account assigned as Researcher on WF-002 (older data).
        AuditLogger::muted(fn () => $this->assignTo($this->projectLeader, $this->otherProject, 'researcher'));

        $this->actingAs($this->programLeader)
            ->patchJson("/api/program/assignments/{$this->projectLeader->id}/{$this->otherProject->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', $this->refusal());
        $this->assertSame('researcher', $this->projectLeader->fresh()->roleOn($this->otherProject));
    }

    // ---------------------------------------------------------------- Existing data

    public function test_leaders_with_more_than_one_active_project_are_listed_and_nothing_changes(): void
    {
        $third = $this->makeLed('WF-003', $this->future(), $this->projectLeader);
        AuditLogger::muted(fn () => $this->assignTo($this->projectLeader, $this->otherProject, 'project-leader'));
        $this->makeLed('OLD-1', $this->past(), $this->projectLeader); // completed: not counted
        $rows = ProjectUser::count();

        foreach (['/api/program/projects', '/api/program/users'] as $url) {
            $conflicts = $this->actingAs($this->programLeader)->getJson($url)->assertOk()->json('leader_conflicts');
            $this->assertCount(1, $conflicts, $url);
            $this->assertSame($this->projectLeader->id, $conflicts[0]['user']['id']);
            $this->assertSame(3, $conflicts[0]['count']);
            $this->assertSame('Leads 3 active projects; reassign all but one', $conflicts[0]['message']);
            $this->assertEqualsCanonicalizing(['WF-001', 'WF-002', 'WF-003'], array_column($conflicts[0]['projects'], 'code'));
        }
        $this->assertSame($rows, ProjectUser::count(), 'listing changes nothing');
        $this->assertTrue($this->projectLeader->hasRoleOn($third, 'project-leader'));
    }

    public function test_no_conflicts_when_every_leader_leads_one_active_project(): void
    {
        $this->makeLed('OLD-1', $this->past(), $this->projectLeader);
        $this->actingAs($this->programLeader)->getJson('/api/program/projects')->assertJsonPath('leader_conflicts', []);
    }

    public function test_the_leader_dropdown_says_which_active_project_each_one_leads(): void
    {
        $free = $this->freeLeader();
        $this->makeLed('OLD-1', $this->past(), $free); // completed: still free

        $leaders = collect($this->actingAs($this->programLeader)->getJson('/api/program/project-options')->assertOk()->json('leaders'))->keyBy('id');
        $this->assertSame([['id' => $this->project->id, 'code' => 'WF-001', 'title' => 'Workflow project']], $leaders[$this->projectLeader->id]['leads']);
        $this->assertSame([], $leaders[$free->id]['leads']);
    }

    // ---------------------------------------------------------------- Past projects

    public function test_past_projects_stay_listed_and_open_read_only_for_their_leader(): void
    {
        $old = $this->makeLed('OLD-1', $this->past(), $this->projectLeader);

        $rows = collect($this->actingAs($this->projectLeader)->getJson('/api/projects')->assertOk()->json())->keyBy('code');
        $this->assertSame('completed', $rows['OLD-1']['status_key']);
        $this->assertSame(['project-leader'], $rows['OLD-1']['my_roles']);
        $this->assertSame('ongoing', $rows['WF-001']['status_key']);

        // The past project opens (the project page).
        $this->getJson("/api/projects/{$old->id}")->assertOk()->assertJsonPath('project.code', 'OLD-1');
    }
}

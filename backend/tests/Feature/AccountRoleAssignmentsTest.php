<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\GeneratesEmployeeIds;
use Tests\TestCase;

/** A person's role on a project is always their account role. */
class AccountRoleAssignmentsTest extends TestCase
{
    use GeneratesEmployeeIds;
    use RefreshDatabase;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->leader = $this->person('program-leader', 'Program Leader');
    }

    public function test_assign_uses_the_account_role_and_refuses_a_different_one(): void
    {
        $assistant = $this->person('research-assistant', 'Papaw Dianalan');
        $project = $this->project('Enhancing Urban Mobility');

        // No role sent: the account role is used.
        $this->actingAs($this->leader)
            ->postJson('/api/program/assignments', ['user_id' => $assistant->id, 'project_id' => $project->id])
            ->assertCreated();
        $this->assertSame('research-assistant', $assistant->roleOn($project));

        // A role that differs from the account role is refused (nothing saved).
        $other = $this->project('Halal Goat Value Chain');
        $this->actingAs($this->leader)
            ->postJson('/api/program/assignments', ['user_id' => $assistant->id, 'project_id' => $other->id, 'role' => 'researcher'])
            ->assertStatus(422)
            ->assertJsonPath('message', "A person's role on a project is always their account role (Research Assistant).");
        $this->assertDatabaseMissing('project_user', ['user_id' => $assistant->id, 'project_id' => $other->id]);
    }

    /** The production report: a user approved moments ago, assigned with user + project only. */
    public function test_a_just_approved_user_can_be_assigned_with_user_and_project_only(): void
    {
        Notification::fake();
        $pending = User::create([
            'name' => 'Papaw Dianalan', 'first_name' => 'Papaw', 'last_name' => 'Dianalan',
            'email' => 'papaw.' . uniqid() . '@s.msumain.edu.ph', 'employee_id' => $this->nextEmployeeId(),
            'password' => Hash::make('password123'), 'position' => 'Instructor',
            'role_id' => null, 'is_active' => false, 'email_verified_at' => now(),
        ]);
        $project = $this->project('Enhancing Urban Mobility');

        $this->actingAs($this->leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", ['decision' => 'approved', 'role' => 'researcher'])
            ->assertOk();

        // The directory then lists them as assignable, with their role.
        $assignable = collect($this->actingAs($this->leader)->getJson('/api/program/users')->json('assignable_users'))->firstWhere('id', $pending->id);
        $this->assertSame('researcher', $assignable['role_slug']);
        $this->assertSame('Researcher', $assignable['role_label']);

        $this->actingAs($this->leader)
            ->postJson('/api/program/assignments', ['user_id' => (string) $pending->id, 'project_id' => (string) $project->id])
            ->assertCreated();
        $this->assertSame('researcher', $pending->fresh()->roleOn($project));
    }

    /** The Assign dialog leaves out a person's projects and greys out projects that already have a leader. */
    public function test_assign_options_carry_each_persons_projects_and_each_projects_leader(): void
    {
        $assistant = $this->person('research-assistant', 'Papaw Dianalan');
        $projectLeader = $this->person('project-leader', 'Norhaya Ali');
        $led = $this->project('Enhancing Urban Mobility');
        $free = $this->project('Halal Goat Value Chain');

        foreach ([[$assistant, $led], [$projectLeader, $led]] as [$user, $project]) {
            $this->actingAs($this->leader)
                ->postJson('/api/program/assignments', ['user_id' => $user->id, 'project_id' => $project->id])
                ->assertCreated();
        }

        $payload = $this->actingAs($this->leader)->getJson('/api/program/users')->assertOk();
        $users = collect($payload->json('assignable_users'))->keyBy('id');
        $this->assertSame([$led->id], $users[$assistant->id]['project_ids']);
        $this->assertSame([$led->id], $users[$projectLeader->id]['project_ids']);

        $projects = collect($payload->json('projects'))->keyBy('id');
        $this->assertSame(['id' => $projectLeader->id, 'name' => 'Norhaya Ali'], $projects[$led->id]['leader']);
        $this->assertNull($projects[$free->id]['leader']);

        // The project page's Add member dialog gets the same row for its project.
        $this->actingAs($this->leader)
            ->getJson('/api/program/projects/' . $led->id)
            ->assertOk()
            ->assertJsonPath('assign_project.id', $led->id)
            ->assertJsonPath('assign_project.leader.name', 'Norhaya Ali');

        // What the greyed-out option says: the server refuses a second leader.
        $second = $this->person('project-leader', 'Second Leader');
        $this->actingAs($this->leader)
            ->postJson('/api/program/assignments', ['user_id' => $second->id, 'project_id' => $led->id])
            ->assertStatus(422);
    }

    public function test_changing_the_account_role_updates_every_assignment_with_one_audit_entry(): void
    {
        $person = $this->person('researcher', 'Sittie Dimaporo');
        [$a, $b] = [$this->project('Project A'), $this->project('Project B')];
        AuditLogger::muted(function () use ($person, $a, $b) {
            $this->assignRow($person, $a, 'researcher');
            $this->assignRow($person, $b, 'researcher');
        });
        $before = AuditTrail::count();

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}/role", ['role' => 'finance-admin', 'reason' => 'Moved to finance'])
            ->assertOk();

        $this->assertSame(['finance-admin', 'finance-admin'], ProjectUser::where('user_id', $person->id)->with('role')->get()->pluck('role.slug')->all());
        $this->assertSame($before + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame('role_changed', $entry->action);
        $this->assertCount(2, $entry->new_values['assignments']);
        $this->assertSame(['researcher', 'finance-admin'], [$entry->new_values['assignments'][0]['from'], $entry->new_values['assignments'][0]['to']]);
    }

    public function test_a_second_project_leader_on_one_project_is_blocked_with_the_project_named(): void
    {
        $project = $this->project('Enhancing Urban Mobility');
        $first = $this->person('project-leader', 'Dr. First Leader');
        AuditLogger::muted(fn () => $this->assignRow($first, $project, 'project-leader'));

        // Changing an account role to Project Leader.
        $member = $this->person('researcher', 'Sittie Dimaporo');
        AuditLogger::muted(fn () => $this->assignRow($member, $project, 'researcher'));
        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$member->id}/role", ['role' => 'project-leader'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enhancing Urban Mobility already has a Project Leader (Dr. First Leader). A project has one Project Leader; change or remove that assignment first.');
        // Nothing changed: same transaction.
        $this->assertSame('researcher', $member->fresh()->primaryRole->slug);
        $this->assertSame('researcher', $member->fresh()->roleOn($project));

        // Assigning a Project Leader account.
        $second = $this->person('project-leader', 'Dr. Second Leader');
        $this->actingAs($this->leader)
            ->postJson('/api/program/assignments', ['user_id' => $second->id, 'project_id' => $project->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enhancing Urban Mobility already has a Project Leader (Dr. First Leader). A project has one Project Leader; change or remove that assignment first.');
        $this->assertDatabaseMissing('project_user', ['user_id' => $second->id, 'project_id' => $project->id]);
    }

    public function test_a_program_leader_cannot_be_assigned_to_a_project(): void
    {
        $other = $this->person('program-leader', 'Another Program Leader');
        $project = $this->project('Enhancing Urban Mobility');

        $this->actingAs($this->leader)
            ->postJson('/api/program/assignments', ['user_id' => $other->id, 'project_id' => $project->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Program Leaders oversee every project and are not assigned to one.');
        $this->assertDatabaseMissing('project_user', ['user_id' => $other->id]);

        // Making someone with assignments a Program Leader asks to remove them first.
        $person = $this->person('researcher', 'Sittie Dimaporo');
        AuditLogger::muted(fn () => $this->assignRow($person, $project, 'researcher'));
        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}/role", ['role' => 'program-leader'])
            ->assertStatus(422);
        $this->assertSame('researcher', $person->fresh()->primaryRole->slug);
    }

    public function test_mismatched_rows_are_flagged_and_fix_sets_the_account_role(): void
    {
        // A Project Leader account assigned as Research Assistant (older data).
        $person = $this->person('project-leader', 'Dr. Mismatch');
        $project = $this->project('Enhancing Urban Mobility');
        AuditLogger::muted(fn () => $this->assignRow($person, $project, 'research-assistant'));

        $row = collect($this->actingAs($this->leader)->getJson('/api/program/users')->json('users'))->firstWhere('id', $person->id);
        $this->assertTrue($row['assignments'][0]['role_mismatch']);
        // Not changed automatically by listing it.
        $this->assertSame('research-assistant', $person->fresh()->roleOn($project));

        $this->actingAs($this->leader)
            ->patchJson("/api/program/assignments/{$person->id}/{$project->id}")
            ->assertOk();

        $this->assertSame('project-leader', $person->fresh()->roleOn($project));
        $row = collect($this->actingAs($this->leader)->getJson('/api/program/users')->json('users'))->firstWhere('id', $person->id);
        $this->assertFalse($row['assignments'][0]['role_mismatch']);
    }

    private function assignRow(User $user, Project $project, string $slug): void
    {
        ProjectUser::create(['user_id' => $user->id, 'project_id' => $project->id, 'role_id' => Role::where('slug', $slug)->value('id'), 'assigned_at' => now()]);
    }

    private function person(string $slug, string $name): User
    {
        return User::create([
            'name' => $name,
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => $this->nextEmployeeId(),
            'password' => Hash::make('password123'),
            'position' => 'Staff',
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function project(string $title): Project
    {
        $funder = FundingAgency::firstOrCreate(['acronym' => 'MOST-BARMM'], ['name' => 'MOST-BARMM']);

        return Project::create([
            'code' => 'ARA-' . uniqid(),
            'title' => $title,
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);
    }
}

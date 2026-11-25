<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ProgramLeaderTransferredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * One Program Leader account: the role is never assigned through approval,
 * Add user or a role change; the last active one stays; it moves only by
 * "Transfer Program Leader role".
 */
class SingleProgramLeaderTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->leader = $this->person('program-leader', 'Current Leader');
    }

    public function test_program_leader_cannot_be_assigned_through_registration_approval(): void
    {
        $pending = $this->pending();

        $this->actingAs($this->leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", ['decision' => 'approved', 'role' => 'program-leader'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        // The older approve endpoint too.
        $this->actingAs($this->leader)
            ->patchJson("/api/users/{$pending->id}/approve", ['role' => 'program-leader'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertNull($pending->fresh()->role_id);
        $this->assertSame(1, $this->activeLeaders());

        // The role list sent to the Review panel leaves it out.
        $roles = collect($this->actingAs($this->leader)->getJson('/api/program/registrations')->json('roles'))->pluck('slug');
        $this->assertNotContains('program-leader', $roles);
    }

    public function test_program_leader_cannot_be_assigned_through_add_user(): void
    {
        $this->actingAs($this->leader)
            ->postJson('/api/program/users', [
                'first_name' => 'New',
                'last_name' => 'Person',
                'email' => 'new.person@msumain.edu.ph',
                'employee_id' => 'MSU-54321',
                'position' => 'Professor',
                'college' => 'College of Information and Computing Sciences',
                'department' => 'Computing Sciences',
                'role' => 'program-leader',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'new.person@msumain.edu.ph']);
        $this->assertSame(1, $this->activeLeaders());
    }

    public function test_program_leader_cannot_be_assigned_through_a_role_change(): void
    {
        $person = $this->person('researcher', 'Some Researcher');

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}/role", ['role' => 'program-leader'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame('researcher', $person->fresh()->primaryRole->slug);

        $roles = collect($this->actingAs($this->leader)->getJson('/api/program/users')->json('roles'))->pluck('slug');
        $this->assertNotContains('program-leader', $roles);
    }

    public function test_the_last_active_program_leader_cannot_be_deactivated_or_have_their_role_changed(): void
    {
        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$this->leader->id}/status", ['is_active' => false, 'reason' => 'Leaving'])
            ->assertStatus(422);
        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$this->leader->id}/role", ['role' => 'researcher'])
            ->assertStatus(422);

        $this->assertTrue($this->leader->fresh()->is_active);
        $this->assertSame('program-leader', $this->leader->fresh()->primaryRole->slug);

        $row = collect($this->actingAs($this->leader)->getJson('/api/program/users')->json('users'))->firstWhere('id', $this->leader->id);
        $this->assertTrue($row['is_last_program_leader']);
    }

    public function test_with_two_program_leaders_one_can_deactivate_the_other_but_not_themselves(): void
    {
        $second = $this->person('program-leader', 'Second Leader');

        $rows = collect($this->actingAs($this->leader)->getJson('/api/program/users')->json('users'));
        $this->assertFalse($rows->firstWhere('id', $second->id)['is_last_program_leader']);

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$this->leader->id}/status", ['is_active' => false, 'reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot deactivate your own account.');

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$second->id}/status", ['is_active' => false, 'reason' => 'Keeping one Program Leader'])
            ->assertOk();

        $this->assertFalse($second->fresh()->is_active);
        $this->assertSame(1, $this->activeLeaders());

        // The remaining one is now the last: it stays, and the other cannot come back.
        $row = collect($this->actingAs($this->leader)->getJson('/api/program/users?status=all')->json('users'))->firstWhere('id', $second->id);
        $this->assertFalse($row['can_reactivate']);
        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$second->id}/status", ['is_active' => true])
            ->assertStatus(422);
        $this->assertSame(1, $this->activeLeaders());
    }

    public function test_transfer_with_the_right_password_moves_the_role_and_leaves_one_active_program_leader(): void
    {
        $target = $this->person('finance-admin', 'Next Leader');
        $project = $this->project();
        ProjectUser::create(['project_id' => $project->id, 'user_id' => $target->id, 'role_id' => $target->role_id, 'assigned_at' => now()]);

        $this->actingAs($this->leader)
            ->postJson('/api/program/transfer-program-leader', ['user_id' => $target->id, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.role', 'PRG')
            ->assertJsonPath('removed_assignments.0.project_id', $project->id);

        $this->assertSame('program-leader', $target->fresh()->primaryRole->slug);
        $this->assertTrue($target->fresh()->is_active);
        $this->assertFalse($this->leader->fresh()->is_active, 'the previous Program Leader is deactivated');
        $this->assertSame('program-leader', $this->leader->fresh()->primaryRole->slug);
        $this->assertSame(1, $this->activeLeaders());
        $this->assertSame(
            [$target->id],
            User::where('is_active', true)->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))->pluck('id')->all()
        );

        // A Program Leader holds no project rows.
        $this->assertDatabaseMissing('project_user', ['user_id' => $target->id]);

        $entry = AuditTrail::where('action', 'program_leader_transferred')->firstOrFail();
        $this->assertSame($this->leader->id, (int) $entry->user_id);
        $this->assertSame($this->leader->name, $entry->old_values['from']);
        $this->assertSame($target->name, $entry->new_values['to']);

        Notification::assertSentTo($this->leader, ProgramLeaderTransferredNotification::class);
        Notification::assertSentTo($target, ProgramLeaderTransferredNotification::class);

        // The previous Program Leader is signed out.
        auth()->forgetGuards();
        $this->actingAs($this->leader->fresh())->getJson('/api/me')->assertStatus(401);
    }

    public function test_transfer_with_a_wrong_password_changes_nothing(): void
    {
        $target = $this->person('researcher', 'Next Leader');

        $this->actingAs($this->leader)
            ->postJson('/api/program/transfer-program-leader', ['user_id' => $target->id, 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame('researcher', $target->fresh()->primaryRole->slug);
        $this->assertTrue($this->leader->fresh()->is_active);
        $this->assertSame(1, $this->activeLeaders());
        $this->assertSame(0, AuditTrail::where('action', 'program_leader_transferred')->count());
        Notification::assertNothingSent();
    }

    public function test_transfer_is_refused_to_an_inactive_account_and_for_non_program_leaders(): void
    {
        $inactive = $this->person('researcher', 'Inactive Person', false);
        $other = $this->person('project-leader', 'Project Leader');

        $this->actingAs($this->leader)
            ->postJson('/api/program/transfer-program-leader', ['user_id' => $inactive->id, 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->actingAs($other)
            ->postJson('/api/program/transfer-program-leader', ['user_id' => $other->id, 'password' => 'password123'])
            ->assertForbidden();

        $this->assertTrue($this->leader->fresh()->is_active);
        $this->assertSame(1, $this->activeLeaders());
    }

    public function test_transfer_is_refused_while_a_second_program_leader_is_active(): void
    {
        $this->person('program-leader', 'Second Leader');
        $target = $this->person('researcher', 'Next Leader');

        $this->actingAs($this->leader)
            ->postJson('/api/program/transfer-program-leader', ['user_id' => $target->id, 'password' => 'password123'])
            ->assertStatus(422);

        $this->assertSame('researcher', $target->fresh()->primaryRole->slug);
        $this->assertTrue($this->leader->fresh()->is_active);
    }

    public function test_transfer_is_refused_when_the_target_is_the_only_project_leader_of_an_ongoing_project(): void
    {
        $target = $this->person('project-leader', 'Leads A Project');
        $project = $this->project();
        ProjectUser::create(['project_id' => $project->id, 'user_id' => $target->id, 'role_id' => $target->role_id, 'assigned_at' => now()]);

        $this->actingAs($this->leader)
            ->postJson('/api/program/transfer-program-leader', ['user_id' => $target->id, 'password' => 'password123'])
            ->assertStatus(422);

        $this->assertSame('project-leader', $target->fresh()->primaryRole->slug);
        $this->assertDatabaseHas('project_user', ['user_id' => $target->id, 'project_id' => $project->id]);
        $this->assertTrue($this->leader->fresh()->is_active);
    }

    private function activeLeaders(): int
    {
        return User::where('is_active', true)
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))
            ->count();
    }

    private function person(string $slug, string $name, bool $active = true): User
    {
        return User::create([
            'name' => $name,
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => 'MSU-' . random_int(10000, 99999),
            'password' => Hash::make('password123'),
            'position' => 'Staff',
            'college' => 'College of Natural Sciences and Mathematics',
            'department' => 'Computer Science',
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => $active,
            'email_verified_at' => now(),
        ]);
    }

    private function pending(): User
    {
        return User::create([
            'name' => 'Pending Person',
            'first_name' => 'Pending',
            'last_name' => 'Person',
            'email' => 'pending.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => 'MSU-' . random_int(10000, 99999),
            'password' => Hash::make('password123'),
            'position' => 'Instructor',
            'college' => 'College of Natural Sciences and Mathematics',
            'department' => 'Computer Science',
            'role_id' => null,
            'is_active' => false,
            'email_verified_at' => now(),
        ]);
    }

    private function project(): Project
    {
        $funder = FundingAgency::firstOrCreate(['acronym' => 'MOST-BARMM'], ['name' => 'MOST-BARMM']);

        return Project::create([
            'code' => 'SPL-' . uniqid(),
            'title' => 'Single leader project',
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2020-01-01',
            'end_date' => '2099-12-31',
            'status' => 'ongoing',
        ]);
    }
}

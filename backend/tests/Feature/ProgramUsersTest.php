<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\AccountRejectedNotification;
use App\Notifications\AccountSetupNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProgramUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_user_endpoints_are_forbidden_for_non_program_leaders(): void
    {
        $projectLeader = $this->userWithRole('project-leader');
        $pending = $this->pendingUser();

        foreach ([
            ['get', '/api/program/registrations'],
            ['get', '/api/program/users'],
            ['get', '/api/program/approvals'],
        ] as [$method, $uri]) {
            $this->actingAs($projectLeader)->{$method.'Json'}($uri)->assertForbidden();
        }

        $this->actingAs($projectLeader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'researcher',
            ])
            ->assertForbidden();

        $this->actingAs($projectLeader)
            ->postJson('/api/program/users', $this->addUserPayload())
            ->assertForbidden();

        $this->actingAs($projectLeader)
            ->postJson('/api/program/assignments', [
                'user_id' => $pending->id,
                'project_id' => $this->project()->id,
                'role' => 'researcher',
            ])
            ->assertForbidden();

        $this->actingAs($projectLeader)
            ->patchJson("/api/program/users/{$pending->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->actingAs($projectLeader)
            ->patchJson("/api/program/users/{$pending->id}/role", ['role' => 'researcher'])
            ->assertForbidden();
    }

    public function test_a_program_leader_can_approve_a_pending_registration_with_a_role(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');
        $pending = $this->pendingUser();

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'research-assistant',
            ])
            ->assertOk()
            ->assertJsonPath('user.role', 'RA');

        $pending->refresh();
        $this->assertSame('research-assistant', $pending->primaryRole->slug);
        $this->assertTrue($pending->is_active);
        $this->assertNull($pending->registration_rejected_at);
        $this->assertDatabaseHas('audit_trails', [
            'user_id' => $leader->id,
            'action' => 'approved',
            'model_type' => 'User',
            'model_id' => $pending->id,
        ]);
        Notification::assertSentTo($pending, AccountApprovedNotification::class);
    }

    public function test_a_program_leader_can_reject_a_pending_registration_with_a_required_reason(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');
        $pending = $this->pendingUser();

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'rejected',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remarks');

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'rejected',
                'remarks' => 'Your employee ID could not be verified.',
            ])
            ->assertOk();

        $pending->refresh();
        $this->assertNull($pending->role_id);
        $this->assertNotNull($pending->registration_rejected_at);
        $this->assertSame('Your employee ID could not be verified.', $pending->registration_rejection_reason);
        $this->assertDatabaseHas('audit_trails', [
            'action' => 'rejected',
            'model_type' => 'User',
            'model_id' => $pending->id,
        ]);
        Notification::assertSentTo($pending, AccountRejectedNotification::class);

        // Rejected accounts leave the pending queue and cannot be approved later.
        $this->actingAs($leader)
            ->getJson('/api/program/registrations')
            ->assertOk()
            ->assertJsonCount(0, 'users');
    }

    public function test_assigning_a_user_to_a_project_creates_one_role_and_rejects_duplicates(): void
    {
        $leader = $this->userWithRole('program-leader');
        $user = $this->userWithRole('researcher');
        $project = $this->project();

        $this->actingAs($leader)
            ->postJson('/api/program/assignments', [
                'user_id' => $user->id,
                'project_id' => $project->id,
                'role' => 'research-assistant',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('project_user', [
            'user_id' => $user->id,
            'project_id' => $project->id,
        ]);
        $this->assertSame('research-assistant', $user->roleOn($project));

        $this->actingAs($leader)
            ->postJson('/api/program/assignments', [
                'user_id' => $user->id,
                'project_id' => $project->id,
                'role' => 'researcher',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This user already has a role on that project. Edit their role instead.');

        $this->assertSame(1, ProjectUser::where('user_id', $user->id)->where('project_id', $project->id)->count());
    }

    public function test_assigning_an_inactive_user_is_rejected(): void
    {
        $leader = $this->userWithRole('program-leader');
        $user = $this->userWithRole('researcher');
        $user->update(['is_active' => false]);

        $this->actingAs($leader)
            ->postJson('/api/program/assignments', [
                'user_id' => $user->id,
                'project_id' => $this->project()->id,
                'role' => 'researcher',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Deactivated users cannot be assigned to projects.');
    }

    public function test_a_program_leader_can_edit_and_remove_a_project_role(): void
    {
        $leader = $this->userWithRole('program-leader');
        $user = $this->userWithRole('researcher');
        $project = $this->project();
        $this->assign($user, $project, 'researcher');

        $this->actingAs($leader)
            ->patchJson("/api/program/assignments/{$user->id}/{$project->id}", ['role' => 'research-assistant'])
            ->assertOk();

        $this->assertSame('research-assistant', $user->fresh()->roleOn($project));

        $this->actingAs($leader)
            ->deleteJson("/api/program/assignments/{$user->id}/{$project->id}")
            ->assertOk();

        $this->assertDatabaseMissing('project_user', [
            'user_id' => $user->id,
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseHas('audit_trails', ['action' => 'assignment_removed', 'model_type' => 'User']);
    }

    public function test_a_program_leader_can_deactivate_and_reactivate_a_user_but_not_themselves(): void
    {
        $leader = $this->userWithRole('program-leader');
        $otherLeader = $this->userWithRole('program-leader');
        $project = $this->project();

        $this->actingAs($leader)
            ->patchJson("/api/program/users/{$leader->id}/status", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot deactivate your own account.');

        $this->actingAs($leader)
            ->patchJson("/api/program/users/{$otherLeader->id}/status", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($otherLeader->fresh()->is_active);

        $this->actingAs($leader)
            ->postJson('/api/program/assignments', [
                'user_id' => $otherLeader->id,
                'project_id' => $project->id,
                'role' => 'project-leader',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Deactivated users cannot be assigned to projects.');

        $this->actingAs($leader)
            ->patchJson("/api/program/users/{$otherLeader->id}/status", ['is_active' => true])
            ->assertOk();

        $this->assertTrue($otherLeader->fresh()->is_active);
    }

    public function test_the_last_active_program_leader_cannot_be_deactivated_or_demoted(): void
    {
        $leader = $this->userWithRole('program-leader');

        // A second leader performs the action so the self-guard is not what fires.
        $second = $this->userWithRole('program-leader');

        $this->actingAs($leader)
            ->patchJson("/api/program/users/{$second->id}/status", ['is_active' => false])
            ->assertOk();

        $this->actingAs($leader)
            ->patchJson("/api/program/users/{$leader->id}/status", ['is_active' => false])
            ->assertStatus(422);

        $this->actingAs($leader)
            ->patchJson("/api/program/users/{$leader->id}/role", ['role' => 'researcher'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot change your own Program Leader role.');
    }

    public function test_a_program_leader_cannot_demote_the_last_active_program_leader(): void
    {
        $leader = $this->userWithRole('program-leader');
        $second = $this->userWithRole('program-leader');
        $second->update(['is_active' => false]);

        $this->actingAs($leader)
            ->patchJson("/api/program/users/{$leader->id}/role", ['role' => 'researcher'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot change your own Program Leader role.');
    }

    public function test_add_user_creates_an_active_account_with_a_hashed_password_and_setup_email(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');

        $this->actingAs($leader)
            ->postJson('/api/program/users', $this->addUserPayload())
            ->assertCreated()
            ->assertJsonPath('user.role', 'PL');

        $user = User::where('email', 'new.leader@msumain.edu.ph')->firstOrFail();
        $this->assertSame('project-leader', $user->primaryRole->slug);
        $this->assertTrue($user->is_active);
        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('temporary-password', $user->password));
        $this->assertDatabaseHas('password_resets', ['email' => $user->email]);
        $this->assertDatabaseHas('audit_trails', [
            'action' => 'created',
            'model_type' => 'User',
            'model_id' => $user->id,
        ]);
        Notification::assertSentTo($user, AccountSetupNotification::class);
    }

    public function test_add_user_accepts_the_student_msu_domain(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');

        $this->actingAs($leader)
            ->postJson('/api/program/users', array_merge($this->addUserPayload(), [
                'email' => 'new.student@s.msumain.edu.ph',
                'employee_id' => 'MSU-54322',
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'new.student@s.msumain.edu.ph',
            'employee_id' => 'MSU-54322',
        ]);
    }

    public function test_add_user_validates_msu_email_and_unique_employee_id(): void
    {
        $leader = $this->userWithRole('program-leader');
        $existing = $this->userWithRole('researcher');
        $existing->update(['employee_id' => 'MSU-12345']);

        $this->actingAs($leader)
            ->postJson('/api/program/users', array_merge($this->addUserPayload(), [
                'email' => 'someone@gmail.com',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->actingAs($leader)
            ->postJson('/api/program/users', array_merge($this->addUserPayload(), [
                'employee_id' => 'MSU-12345',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_demoting_a_user_from_program_leader_requires_another_active_leader(): void
    {
        $leader = $this->userWithRole('program-leader');
        $second = $this->userWithRole('program-leader');

        $this->actingAs($leader)
            ->patchJson("/api/program/users/{$second->id}/role", ['role' => 'researcher'])
            ->assertOk();

        $this->assertSame('researcher', $second->fresh()->primaryRole->slug);
    }

    public function test_deactivating_a_user_blocks_new_payroll_entries(): void
    {
        $user = $this->userWithRole('research-assistant');
        $user->update(['is_active' => false]);
        $project = $this->project();
        $category = \App\Models\LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'PS',
            'line_item_name' => 'Personnel',
            'allocated_amount' => 100000,
        ]);

        $this->expectException(ValidationException::class);

        Payroll::create([
            'project_id' => $project->id,
            'lib_category_id' => $category->id,
            'personnel_id' => $user->id,
            'computed_by' => $user->id,
            'pay_month' => 11,
            'pay_year' => 2026,
            'basic_rate' => 10000,
            'gross_pay' => 10000,
            'total_deductions' => 0,
            'net_pay' => 10000,
            'status' => 'draft',
        ]);
    }

    private function addUserPayload(): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Leader',
            'email' => 'new.leader@msumain.edu.ph',
            'employee_id' => 'MSU-54321',
            'position' => 'Assistant Professor',
            'college' => 'College of Natural Sciences and Mathematics',
            'department' => 'Computer Science',
            'role' => 'project-leader',
        ];
    }

    private function pendingUser(): User
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
        $funder = FundingAgency::firstOrCreate(
            ['acronym' => 'MOST-BARMM'],
            ['name' => 'MOST-BARMM']
        );

        return Project::create([
            'code' => 'PRG-TEST-' . uniqid(),
            'title' => 'Program test project',
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);
    }

    private function assign(User $user, Project $project, string $role): void
    {
        $roleId = Role::where('slug', $role)->firstOrFail()->id;
        DB::table('project_user')->insert([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::create([
            'name' => ucfirst($slug) . ' User ' . uniqid(),
            'first_name' => ucfirst($slug),
            'last_name' => 'User',
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => 'MSU-' . random_int(10000, 99999),
            'password' => Hash::make('password123'),
            'position' => 'Staff',
            'college' => 'College of Natural Sciences and Mathematics',
            'department' => 'Computer Science',
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}

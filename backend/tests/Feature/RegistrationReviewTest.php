<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\AccountRejectedNotification;
use App\Notifications\NewRegistrationPendingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** One-step registration review (role + project assignments) and new-registration notifications. */
class RegistrationReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_with_role_and_assignments_saves_everything_in_one_step(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');
        $pending = $this->pendingUser();
        [$a, $b] = [$this->project('A'), $this->project('B')];

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'research-assistant',
                // No role per row: the role on each project is the account role.
                'assignments' => [
                    ['project_id' => $a->id],
                    ['project_id' => $b->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('user.role', 'RA');

        $pending->refresh();
        $this->assertTrue($pending->is_active);
        $this->assertSame('research-assistant', $pending->primaryRole->slug);
        $this->assertSame(2, ProjectUser::where('user_id', $pending->id)->count());
        $this->assertDatabaseHas('project_user', ['user_id' => $pending->id, 'project_id' => $b->id, 'role_id' => $this->roleId('research-assistant')]);

        // One audit entry, listing the role and the assignments.
        $entries = AuditTrail::where('model_type', 'User')->where('model_id', $pending->id)->where('action', '!=', 'created')->get();
        $this->assertCount(1, $entries);
        $this->assertSame('approved', $entries[0]->action);
        $this->assertSame('research-assistant', $entries[0]->new_values['role']);
        $this->assertCount(2, $entries[0]->new_values['assignments']);
        $this->assertSame(0, AuditTrail::where('action', 'assignment_created')->count());
    }

    public function test_an_invalid_assignment_saves_nothing_and_names_the_row(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');
        $pending = $this->pendingUser();
        $a = $this->project('A');

        // Same project twice: the second row is wrong.
        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'researcher',
                'assignments' => [
                    ['project_id' => $a->id, 'role' => 'researcher'],
                    ['project_id' => $a->id, 'role' => 'finance-admin'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignments.1.project_id');

        // A project that does not exist.
        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'researcher',
                'assignments' => [
                    ['project_id' => $a->id, 'role' => 'researcher'],
                    ['project_id' => 999999, 'role' => 'researcher'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignments.1.project_id');

        $pending->refresh();
        $this->assertNull($pending->role_id);
        $this->assertFalse((bool) $pending->is_active);
        $this->assertSame(0, ProjectUser::where('user_id', $pending->id)->count());
        $this->assertSame(0, AuditTrail::where('model_type', 'User')->where('model_id', $pending->id)->where('action', 'approved')->count());
        Notification::assertNotSentTo($pending, AccountApprovedNotification::class);
    }

    public function test_a_rule_broken_inside_the_transaction_rolls_back_the_approval(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');
        $pending = $this->pendingUser();
        $a = $this->project('A');
        $b = $this->project('B');
        // A leftover row (e.g. seeded data) makes the second assignment break
        // the "one role per project" rule only once inside the transaction.
        ProjectUser::create(['user_id' => $pending->id, 'project_id' => $b->id, 'role_id' => $this->roleId('researcher'), 'assigned_at' => now()]);

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'researcher',
                'assignments' => [
                    ['project_id' => $a->id, 'role' => 'researcher'],
                    ['project_id' => $b->id, 'role' => 'researcher'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignments.1.project_id');

        $pending->refresh();
        $this->assertNull($pending->role_id);
        $this->assertFalse((bool) $pending->is_active);
        $this->assertDatabaseMissing('project_user', ['user_id' => $pending->id, 'project_id' => $a->id]);
    }

    public function test_a_program_leader_role_with_project_rows_is_rejected(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');
        $pending = $this->pendingUser();

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'program-leader',
                'assignments' => [['project_id' => $this->project('A')->id, 'role' => 'project-leader']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertNull($pending->fresh()->role_id);

        // One Program Leader: the role is never given by approval, even without project rows.
        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", ['decision' => 'approved', 'role' => 'program-leader'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
        $this->assertNull($pending->fresh()->role_id);
    }

    public function test_reject_requires_a_reason_and_the_user_cannot_sign_in(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');
        $pending = $this->pendingUser();

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", ['decision' => 'rejected', 'remarks' => '  '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remarks');

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", ['decision' => 'rejected', 'remarks' => 'Not a member of the program.'])
            ->assertOk();

        $this->assertFalse((bool) $pending->fresh()->is_active);

        auth()->guard('web')->logout();
        $this->postJson('/api/login', ['email' => $pending->email, 'password' => 'password123'])
            ->assertForbidden();
    }

    public function test_approval_and_rejection_each_send_one_email(): void
    {
        Notification::fake();
        $leader = $this->userWithRole('program-leader');
        $approved = $this->pendingUser();
        $rejected = $this->pendingUser();

        $this->actingAs($leader)->postJson("/api/program/registrations/{$approved->id}/decision", [
            'decision' => 'approved',
            'role' => 'researcher',
            'assignments' => [['project_id' => $this->project('A')->id, 'role' => 'researcher']],
        ])->assertOk();
        $this->actingAs($leader)->postJson("/api/program/registrations/{$rejected->id}/decision", [
            'decision' => 'rejected',
            'remarks' => 'Duplicate account.',
        ])->assertOk();

        Notification::assertSentToTimes($approved, AccountApprovedNotification::class, 1);
        Notification::assertNotSentTo($approved, AccountRejectedNotification::class);
        Notification::assertSentToTimes($rejected, AccountRejectedNotification::class, 1);
        Notification::assertSentTo($rejected, AccountRejectedNotification::class, fn ($n) => $n->reason === 'Duplicate account.');
        Notification::assertNotSentTo($rejected, AccountApprovedNotification::class);

        $mail = (new AccountApprovedNotification($approved, 'Researcher'))->toMail($approved);
        $this->assertStringEndsWith('/login', $mail->actionUrl);
    }

    public function test_a_mailer_failure_is_logged_and_the_approval_still_finishes(): void
    {
        config(['mail.default' => 'failing']);
        config(['mail.mailers.failing' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $leader = $this->userWithRole('program-leader');
        $pending = $this->pendingUser();

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", ['decision' => 'approved', 'role' => 'researcher'])
            ->assertOk();

        $this->assertTrue((bool) $pending->fresh()->is_active);
    }

    public function test_a_new_registration_notifies_each_active_program_leader_once_and_the_badge_matches(): void
    {
        $leaderA = $this->userWithRole('program-leader');
        $leaderB = $this->userWithRole('program-leader');
        $inactive = $this->userWithRole('program-leader');
        $inactive->update(['is_active' => false]);
        $other = $this->userWithRole('finance-admin');

        $this->postJson('/api/register', $this->registerPayload())->assertCreated();
        $applicant = User::where('email', 'new.applicant@s.msumain.edu.ph')->firstOrFail();

        foreach ([$leaderA, $leaderB] as $leader) {
            $this->assertSame(1, $leader->notifications()->where('type', NewRegistrationPendingNotification::class)->count());
        }
        $this->assertSame(0, $inactive->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());

        $row = $this->actingAs($leaderA)->getJson('/api/notifications')->assertOk()->json('notifications.0');
        $this->assertSame('New account request: Nora Applicant, College of Natural Sciences and Mathematics', $row['message']);
        $this->assertSame(['type' => 'registration', 'id' => $applicant->id], $row['record']);

        $this->actingAs($leaderA)->getJson('/api/program/registrations/count')->assertOk()->assertJsonPath('counts.pending', 1);
        $this->actingAs($leaderA)->getJson('/api/program/registrations')->assertOk()->assertJsonPath('counts.pending', 1);

        // Once one Program Leader decides, the entry is done for both and the badge clears.
        Notification::fake();
        $this->actingAs($leaderA)
            ->postJson("/api/program/registrations/{$applicant->id}/decision", ['decision' => 'approved', 'role' => 'researcher'])
            ->assertOk();

        $this->assertSame(0, $leaderA->unreadNotifications()->count());
        $this->assertSame(0, $leaderB->unreadNotifications()->count());
        $this->actingAs($leaderB)->getJson('/api/program/registrations/count')->assertJsonPath('counts.pending', 0);
    }

    public function test_only_the_program_leader_can_review(): void
    {
        $pending = $this->pendingUser();

        foreach (['project-leader', 'research-assistant', 'researcher', 'finance-admin'] as $slug) {
            $user = $this->userWithRole($slug);
            $this->actingAs($user)->getJson('/api/program/registrations')->assertForbidden();
            $this->actingAs($user)->getJson('/api/program/registrations/count')->assertForbidden();
            $this->actingAs($user)
                ->postJson("/api/program/registrations/{$pending->id}/decision", [
                    'decision' => 'approved',
                    'role' => 'researcher',
                    'assignments' => [['project_id' => $this->project($slug)->id, 'role' => 'researcher']],
                ])
                ->assertForbidden();
        }

        $this->assertNull($pending->fresh()->role_id);
    }

    private function registerPayload(): array
    {
        return [
            'first_name' => 'Nora',
            'last_name' => 'Applicant',
            'email' => 'new.applicant@s.msumain.edu.ph',
            'password' => 'Vq7#mLp2!xRt9z',
            'employee_id' => 'MSU-54321',
            'contact_number' => '09171234567',
            'position' => 'Instructor',
            'college' => 'College of Natural Sciences and Mathematics',
            'department' => 'Mathematics',
        ];
    }

    private function roleId(string $slug): int
    {
        return (int) Role::where('slug', $slug)->value('id');
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

    private function project(string $suffix): Project
    {
        $funder = FundingAgency::firstOrCreate(['acronym' => 'MOST-BARMM'], ['name' => 'MOST-BARMM']);

        return Project::create([
            'code' => 'REV-' . $suffix . '-' . uniqid(),
            'title' => 'Review project ' . $suffix,
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);
    }

    private function userWithRole(string $slug): User
    {
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
            'role_id' => $this->roleId($slug),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}

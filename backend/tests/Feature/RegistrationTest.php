<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Norjannah',
            'last_name' => 'Haron',
            'email' => 'new.user@msumain.edu.ph',
            'password' => 'haron12345_0',
            'employee_id' => 'MSU-99999',
            'contact_number' => '09096254046',
            'college' => 'College of Business and Finance',
            'department' => 'Finance',
        ], $overrides);
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::create([
            'name' => ucfirst($slug) . ' User',
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function test_registration_creates_a_pending_roleless_account_without_a_session(): void
    {
        $this->postJson('/api/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('pending', true)
            ->assertJsonPath('user.email', 'new.user@msumain.edu.ph')
            ->assertJsonPath('user.role', null)
            ->assertJsonPath('user.status', 'Pending');

        $this->assertGuest();

        $this->assertDatabaseHas('users', [
            'email' => 'new.user@msumain.edu.ph',
            'role_id' => null,
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'new.user@msumain.edu.ph',
            'password' => 'haron12345_0',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is awaiting Program Leader approval.');
    }

    public function test_program_leader_can_approve_a_pending_account_and_assign_a_role(): void
    {
        $leader = $this->userWithRole('program-leader');

        $this->postJson('/api/register', $this->payload())->assertCreated();

        $pending = User::where('email', 'new.user@msumain.edu.ph')->firstOrFail();

        $this->actingAs($leader)
            ->getJson('/api/users/pending')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.email', 'new.user@msumain.edu.ph');

        $this->actingAs($leader)
            ->patchJson("/api/users/{$pending->id}/approve", ['role' => 'project-leader'])
            ->assertOk()
            ->assertJsonPath('user.role', 'PL');

        $pending->refresh();
        $this->assertNotNull($pending->role_id);
        $this->assertTrue($pending->is_active);

        $this->postJson('/api/logout')->assertNoContent();

        $this->postJson('/api/login', [
            'email' => 'new.user@msumain.edu.ph',
            'password' => 'haron12345_0',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'PL');
    }

    public function test_duplicate_email_returns_a_validation_error_instead_of_a_server_error(): void
    {
        $role = Role::where('slug', 'program-leader')->firstOrFail();

        User::create([
            'name' => 'Existing User',
            'email' => 'new.user@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->postJson('/api/register', $this->payload([
            'employee_id' => 'MSU-99998',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }
}

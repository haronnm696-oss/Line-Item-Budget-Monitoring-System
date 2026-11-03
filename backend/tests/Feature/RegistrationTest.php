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

    private function role(string $slug = 'program-leader'): Role
    {
        return Role::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Program Leader']
        );
    }

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
            'role' => 'program-leader',
        ], $overrides);
    }

    public function test_registration_starts_an_authenticated_session(): void
    {
        $this->role();

        $this->postJson('/api/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('user.email', 'new.user@msumain.edu.ph');

        $this->assertAuthenticated();
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'new.user@msumain.edu.ph');
    }

    public function test_duplicate_email_returns_a_validation_error_instead_of_a_server_error(): void
    {
        $role = $this->role();

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

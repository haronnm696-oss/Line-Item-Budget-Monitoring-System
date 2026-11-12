<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every role must be able to sign in with email + password. The backend,
     * not a role dropdown, decides which dashboard the account lands on.
     */
    public function test_every_role_can_sign_in_with_email_and_password(): void
    {
        $accounts = [
            'program-leader' => 'PRG',
            'project-leader' => 'PL',
            'research-assistant' => 'RA',
            'researcher' => 'RES',
            'finance-admin' => 'FIN',
        ];

        foreach ($accounts as $slug => $code) {
            $role = Role::where('slug', $slug)->firstOrFail();
            $email = 'login.' . str_replace('-', '.', $slug) . '@msumain.edu.ph';

            User::create([
                'name' => ucfirst($slug) . ' User',
                'email' => $email,
                'password' => Hash::make('password123'),
                'role_id' => $role->id,
                'is_active' => true,
            ]);

            $this->postJson('/api/login', [
                'email' => $email,
                'password' => 'password123',
            ])
                ->assertOk()
                ->assertJsonPath('user.email', $email)
                ->assertJsonPath('user.role', $code)
                ->assertJsonPath('user.role_slug', $slug)
                ->assertJsonPath('user.status', 'Active');

            $this->postJson('/api/logout')->assertNoContent();
        }
    }

    public function test_student_and_staff_email_domains_can_sign_in(): void
    {
        $role = Role::where('slug', 'researcher')->firstOrFail();

        foreach (['student@s.msumain.edu.ph', 'staff@msumain.edu.ph'] as $email) {
            User::create([
                'name' => 'Domain Test User',
                'email' => $email,
                'password' => Hash::make('password123'),
                'role_id' => $role->id,
                'is_active' => true,
            ]);

            $this->postJson('/api/login', [
                'email' => $email,
                'password' => 'password123',
            ])
                ->assertOk()
                ->assertJsonPath('user.email', $email);

            $this->postJson('/api/logout')->assertNoContent();
        }
    }

    public function test_deactivated_account_cannot_sign_in(): void
    {
        $role = Role::where('slug', 'researcher')->firstOrFail();

        User::create([
            'name' => 'Inactive User',
            'email' => 'inactive.user@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive.user@msumain.edu.ph',
            'password' => 'password123',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is deactivated. Contact your administrator.');
    }
}

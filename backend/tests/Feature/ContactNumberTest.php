<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Contact number rule (every request that saves one): exactly 11 digits
 * starting with 09. Required on Create account, optional when the Program
 * Leader adds or edits a user. A saved number that does not match is kept
 * only while unchanged.
 */
class ContactNumberTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Enter an 11-digit mobile number starting with 09';

    /** 10 digits, 12 digits, letters, not starting with 09, formatted. */
    private const INVALID = ['0917123456', '091712345678', '0917abc4567', '19171234567', '08171234567', '+639171234567', '0917-123-4567'];

    private function person(string $slug, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($slug) . ' ' . uniqid(),
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => 'MSU-' . random_int(10000, 99999),
            'password' => Hash::make('password123'),
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ], $extra));
    }

    private function registration(string $contact): array
    {
        return [
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana.' . uniqid() . '@s.msumain.edu.ph',
            'password' => 'Str0ng!Pass2026', 'employee_id' => 'MSU-' . random_int(10000, 99999),
            'contact_number' => $contact, 'college' => 'College of Business and Finance',
            'department' => 'Finance', 'position' => 'Instructor',
        ];
    }

    private function newUser(?string $contact): array
    {
        return [
            'first_name' => 'New', 'last_name' => 'Person', 'email' => 'new.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => 'MSU-' . random_int(10000, 99999), 'position' => 'Staff',
            'college' => 'College of Natural Sciences and Mathematics', 'department' => 'Computer Science',
            'role' => 'researcher', 'contact_number' => $contact,
        ];
    }

    public function test_create_account_accepts_a_valid_number_and_rejects_the_rest(): void
    {
        $this->postJson('/api/register', $this->registration('09171234567'))->assertCreated();
        $this->assertDatabaseHas('users', ['contact_number' => '09171234567']);

        foreach (self::INVALID as $bad) {
            Cache::flush(); // the register route is throttled
            $this->postJson('/api/register', $this->registration($bad))
                ->assertStatus(422)
                ->assertJsonPath('errors.contact_number.0', self::MESSAGE);
        }
        // Still required on Create account.
        Cache::flush();
        $this->postJson('/api/register', $this->registration(''))->assertStatus(422)->assertJsonValidationErrors('contact_number');
    }

    public function test_adding_a_user_keeps_the_number_optional_but_valid(): void
    {
        Notification::fake();
        $leader = $this->person('program-leader');
        $this->actingAs($leader);

        $this->postJson('/api/program/users', $this->newUser('09171234567'))->assertCreated();
        $this->postJson('/api/program/users', $this->newUser(null))->assertCreated();
        foreach (self::INVALID as $bad) {
            $this->postJson('/api/program/users', $this->newUser($bad))
                ->assertStatus(422)
                ->assertJsonPath('errors.contact_number.0', self::MESSAGE);
        }
    }

    public function test_edit_details_keeps_an_unchanged_legacy_number_and_checks_a_changed_one(): void
    {
        $leader = $this->person('program-leader');
        $legacy = $this->person('researcher', ['contact_number' => '+63 917 123 4567']);
        $this->actingAs($leader);
        $details = fn (array $extra = []) => array_merge(['name' => $legacy->name, 'email' => $legacy->email, 'employee_id' => $legacy->employee_id], $extra);

        // Unchanged legacy number: allowed, kept as it is.
        $this->patchJson("/api/program/users/{$legacy->id}", $details(['contact_number' => '+63 917 123 4567', 'position' => 'Analyst']))->assertOk();
        $this->assertSame('+63 917 123 4567', $legacy->fresh()->contact_number);
        $this->assertSame('Analyst', $legacy->fresh()->position);
        // Not sent at all (an older client): left as it is.
        $this->patchJson("/api/program/users/{$legacy->id}", $details())->assertOk();
        $this->assertSame('+63 917 123 4567', $legacy->fresh()->contact_number);

        // Changed: must match.
        foreach (self::INVALID as $bad) {
            $this->patchJson("/api/program/users/{$legacy->id}", $details(['contact_number' => $bad]))
                ->assertStatus(422)
                ->assertJsonPath('errors.contact_number.0', self::MESSAGE);
        }
        $this->assertSame('+63 917 123 4567', $legacy->fresh()->contact_number);

        $this->patchJson("/api/program/users/{$legacy->id}", $details(['contact_number' => '09181234567']))
            ->assertOk()
            ->assertJsonPath('user.contact_number', '09181234567');

        // Optional: clearing it is fine.
        $this->patchJson("/api/program/users/{$legacy->id}", $details(['contact_number' => '']))->assertOk();
        $this->assertNull($legacy->fresh()->contact_number);
    }
}

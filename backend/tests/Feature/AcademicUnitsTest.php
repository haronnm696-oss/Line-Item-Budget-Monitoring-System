<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AcademicUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\GeneratesEmployeeIds;
use Tests\TestCase;

/**
 * MSU-Main colleges / units (config/academic_units.php): the list served to
 * the frontend, and the college + department rule on Create account, Add
 * user and Edit details. Saved values not in the list are kept while unchanged.
 */
class AcademicUnitsTest extends TestCase
{
    use GeneratesEmployeeIds;
    use RefreshDatabase;

    private function person(string $slug, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($slug) . ' ' . uniqid(),
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => $this->nextEmployeeId(),
            'password' => Hash::make('password123'),
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ], $extra));
    }

    private function register(array $unit)
    {
        Cache::flush(); // the register route is throttled

        return $this->postJson('/api/register', array_merge([
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana.' . uniqid() . '@s.msumain.edu.ph',
            'password' => 'Str0ng!Pass2026', 'employee_id' => $this->nextEmployeeId(),
            'contact_number' => '09171234567', 'position' => 'Instructor',
        ], $unit));
    }

    private function addUser(array $unit)
    {
        return $this->postJson('/api/program/users', array_merge([
            'first_name' => 'New', 'last_name' => 'Person', 'email' => 'new.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => $this->nextEmployeeId(), 'position' => 'Staff', 'role' => 'researcher',
        ], $unit));
    }

    public function test_the_list_is_sorted_with_graduate_studies_and_other_last(): void
    {
        $units = $this->getJson('/api/academic-units')->assertOk()->json('units');
        $names = array_column($units, 'name');

        $this->assertCount(19, $units);
        $this->assertSame('Other office / unit', end($names));
        $listed = array_slice($names, 0, -1);
        $sorted = $listed;
        natcasesort($sorted);
        $this->assertSame(array_values($sorted), $listed, 'A to Z by name');
        $this->assertSame('College of Agriculture', $names[0]);
        $this->assertNotContains('College of Business and Finance', $names);

        $byName = collect($units)->keyBy('name');
        $this->assertSame(['Computing Sciences', 'Information Sciences', 'Graduate Studies'], $byName['College of Information and Computing Sciences']['departments']);
        foreach (['Institute of Science Education', 'College of Law', 'College of Medicine', 'Other office / unit'] as $none) {
            $this->assertSame([], $byName[$none]['departments'], $none);
        }
        foreach ($units as $unit) {
            if ($unit['departments'] !== []) {
                $this->assertSame('Graduate Studies', end($unit['departments']), $unit['name']);
            }
        }
        $this->assertTrue($byName['Other office / unit']['other']);
        $this->getJson('/api/academic-units')->assertJsonPath('other.office_max', 150);
    }

    public function test_a_valid_college_and_department_are_accepted(): void
    {
        $this->register(['college' => 'College of Engineering', 'department' => 'Civil Engineering'])->assertCreated();
        $this->register(['college' => 'College of Engineering', 'department' => 'Graduate Studies'])->assertCreated();
        $this->assertDatabaseHas('users', ['college' => 'College of Engineering', 'department' => 'Civil Engineering']);
    }

    public function test_a_department_from_another_college_is_rejected(): void
    {
        $this->register(['college' => 'College of Engineering', 'department' => 'Biology'])
            ->assertStatus(422)
            ->assertJsonPath('errors.department.0', 'Select a department of the College of Engineering.');
        $this->register(['college' => 'College of Engineering'])
            ->assertStatus(422)->assertJsonPath('errors.department.0', 'Select your department.');
        $this->register(['college' => 'College of Business and Finance', 'department' => 'Finance'])
            ->assertStatus(422)->assertJsonPath('errors.college.0', 'Select a college or unit from the list.');
        $this->register([])->assertStatus(422)->assertJsonPath('errors.college.0', 'Select your college or unit.');

        // Reported together with the other field errors.
        $this->register(['college' => 'College of Engineering', 'department' => 'Biology', 'contact_number' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors(['department', 'contact_number']);
    }

    public function test_a_unit_without_departments_is_accepted_without_a_department(): void
    {
        $this->register(['college' => 'College of Law'])->assertCreated();
        // A stray department is not stored for a unit that has none.
        $this->register(['college' => 'College of Medicine', 'department' => 'Surgery', 'email' => 'med@s.msumain.edu.ph'])->assertCreated();
        $this->assertNull(User::where('email', 'med@s.msumain.edu.ph')->value('department'));
    }

    public function test_other_office_requires_the_office_name(): void
    {
        $this->register(['college' => 'Other office / unit'])
            ->assertStatus(422)->assertJsonPath('errors.office_name.0', 'Enter the office or unit name.');
        $this->register(['college' => 'Other office / unit', 'office_name' => str_repeat('x', 151)])
            ->assertStatus(422)->assertJsonPath('errors.office_name.0', 'The office or unit name may not be longer than 150 characters.');

        $this->register(['college' => 'Other office / unit', 'office_name' => 'Office of the Vice Chancellor for Research and Extension', 'email' => 'ovcre@s.msumain.edu.ph'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'ovcre@s.msumain.edu.ph', 'college' => 'Other office / unit', 'department' => 'Office of the Vice Chancellor for Research and Extension']);
    }

    public function test_add_user_uses_the_same_rule(): void
    {
        Notification::fake();
        $this->actingAs($this->person('program-leader'));

        $this->addUser(['college' => 'College of Natural Sciences and Mathematics', 'department' => 'Physics'])->assertCreated();
        $this->addUser(['college' => 'College of Natural Sciences and Mathematics', 'department' => 'Nursing'])
            ->assertStatus(422)->assertJsonPath('errors.department.0', 'Select a department of the College of Natural Sciences and Mathematics.');
        $this->addUser(['college' => 'Institute of Science Education'])->assertCreated();
        $this->addUser(['college' => 'Other office / unit'])->assertStatus(422)->assertJsonValidationErrors('office_name');
        $this->addUser([])->assertStatus(422)->assertJsonValidationErrors('college');
    }

    public function test_a_legacy_value_is_allowed_while_unchanged(): void
    {
        $leader = $this->person('program-leader');
        $legacy = $this->person('researcher', ['college' => 'College of Business and Finance', 'department' => 'Finance']);
        $this->actingAs($leader);
        $details = fn (array $extra = []) => array_merge(['name' => $legacy->name, 'email' => $legacy->email, 'employee_id' => $legacy->employee_id], $extra);

        // Other fields saved with the old pair unchanged.
        $this->patchJson("/api/program/users/{$legacy->id}", $details(['position' => 'Analyst', 'college' => 'College of Business and Finance', 'department' => 'Finance']))
            ->assertOk()
            ->assertJsonPath('user.college', 'College of Business and Finance')
            ->assertJsonPath('user.department', 'Finance');
        $this->assertSame('Analyst', $legacy->fresh()->position);
        // An older client that does not send them: left alone.
        $this->patchJson("/api/program/users/{$legacy->id}", $details())->assertOk();
        $this->assertSame('Finance', $legacy->fresh()->department);

        // Changed: must come from the list.
        $this->patchJson("/api/program/users/{$legacy->id}", $details(['college' => 'College of Business and Finance', 'department' => 'Accounting']))
            ->assertStatus(422)->assertJsonPath('errors.college.0', 'Select a college or unit from the list.');
        $this->patchJson("/api/program/users/{$legacy->id}", $details(['college' => 'College of Business Administration and Accountancy', 'department' => 'Finance']))
            ->assertStatus(422)->assertJsonValidationErrors('department');
        $this->assertSame('College of Business and Finance', $legacy->fresh()->college);

        $this->patchJson("/api/program/users/{$legacy->id}", $details(['college' => 'College of Business Administration and Accountancy', 'department' => 'Accountancy']))->assertOk();
        $this->assertSame('Accountancy', $legacy->fresh()->department);
    }

    public function test_the_report_counts_users_not_in_the_list(): void
    {
        $this->person('researcher', ['college' => 'College of Business and Finance', 'department' => 'Finance']);
        $this->person('researcher', ['college' => 'College of Engineering', 'department' => 'Civil Engineering']);
        $this->person('researcher', ['college' => 'Other office / unit', 'department' => 'OVCRE']);
        $this->person('researcher', ['college' => 'College of Engineering', 'department' => 'Research']);

        $this->assertCount(2, AcademicUnits::legacyUsers());
        $this->artisan('users:academic-units-report')
            ->expectsOutput('2 of 4 users have a college / department not in the list.')
            ->assertExitCode(0);
    }
}

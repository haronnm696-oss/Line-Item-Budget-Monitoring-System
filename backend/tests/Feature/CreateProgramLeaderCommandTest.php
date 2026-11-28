<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateProgramLeaderCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Strong#Pass2026';

    public function test_it_creates_an_active_program_leader_with_an_audit_entry(): void
    {
        $this->artisan('lib:create-program-leader')
            ->expectsQuestion('First name', 'Amina')
            ->expectsQuestion('Last name', 'Dimaporo')
            ->expectsQuestion('Email', 'Amina.Leader@Example.edu')
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password (hidden)', self::PASSWORD)
            ->assertExitCode(0);

        $user = User::where('email', 'amina.leader@example.edu')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->isProgramLeader());
        $this->assertSame('Amina Dimaporo', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        $entries = AuditTrail::where('model_type', 'User')->where('model_id', $user->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('created', $entries[0]->action);
        $this->assertEquals($user->id, $entries[0]->user_id);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($entries[0]->new_values));
    }

    public function test_it_refuses_when_an_active_program_leader_exists(): void
    {
        User::create([
            'name' => 'Existing Leader',
            'email' => 'existing@example.edu',
            'password' => Hash::make('x'),
            'role_id' => Role::where('slug', 'program-leader')->value('id'),
            'is_active' => true,
        ]);

        $this->artisan('lib:create-program-leader')
            ->expectsOutput('An active Program Leader already exists. Only one Program Leader is allowed.')
            ->assertExitCode(1);

        $this->assertSame(1, User::count());
    }

    public function test_it_refuses_a_weak_or_mismatched_password(): void
    {
        $this->artisan('lib:create-program-leader', ['--first-name' => 'Amina', '--last-name' => 'Dimaporo', '--email' => 'amina@example.edu'])
            ->expectsQuestion('Password (hidden)', 'short')
            ->expectsQuestion('Confirm password (hidden)', 'short')
            ->assertExitCode(1);

        $this->artisan('lib:create-program-leader', ['--first-name' => 'Amina', '--last-name' => 'Dimaporo', '--email' => 'amina@example.edu'])
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password (hidden)', self::PASSWORD . 'x')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }
}

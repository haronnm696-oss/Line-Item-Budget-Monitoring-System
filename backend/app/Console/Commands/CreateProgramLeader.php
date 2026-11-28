<?php

namespace App\Console\Commands;

use App\Models\AuditTrail;
use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the first Program Leader on a fresh database. Registration can
 * never create one (single Program Leader rule), so this is the only way in.
 * The password is read with hidden input and never accepted as an option,
 * so it does not end up in shell history.
 */
class CreateProgramLeader extends Command
{
    protected $signature = 'lib:create-program-leader
        {--first-name= : First name}
        {--last-name= : Last name}
        {--email= : Email address used to sign in}
        {--position=Program Leader : Position shown on reports}';

    protected $description = 'Create the first Program Leader account (refused if an active Program Leader exists).';

    public function handle(): int
    {
        $role = Role::where('slug', 'program-leader')->first();
        if (! $role) {
            $this->error('The program-leader role is missing. Run php artisan migrate first.');

            return self::FAILURE;
        }

        if ($this->activeProgramLeaderExists()) {
            $this->error('An active Program Leader already exists. Only one Program Leader is allowed.');

            return self::FAILURE;
        }

        $firstName = trim((string) ($this->option('first-name') ?: $this->ask('First name')));
        $lastName = trim((string) ($this->option('last-name') ?: $this->ask('Last name')));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $position = trim((string) $this->option('position')) ?: 'Program Leader';

        $password = (string) $this->secret('Password (hidden)');
        $confirmation = (string) $this->secret('Confirm password (hidden)');

        $validator = Validator::make([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'position' => $position,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'position' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword($firstName, $lastName, $email)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($role, $firstName, $lastName, $email, $position, $password) {
            // Checked again inside the transaction in case two runs overlap.
            if ($this->activeProgramLeaderExists()) {
                return null;
            }

            // The explicit entry below replaces the observer's generic one.
            return AuditLogger::muted(function () use ($role, $firstName, $lastName, $email, $position, $password) {
                $user = User::create([
                    'name' => trim($firstName . ' ' . $lastName),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'position' => $position,
                    'role_id' => $role->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);

                AuditTrail::record(
                    $user,
                    'created',
                    $user,
                    [],
                    $user->only(['name', 'email', 'position', 'role_id', 'is_active']),
                    'First Program Leader created with php artisan lib:create-program-leader'
                );

                return $user;
            });
        });

        if (! $user) {
            $this->error('An active Program Leader already exists. Only one Program Leader is allowed.');

            return self::FAILURE;
        }

        $this->info("Program Leader created: {$user->name} <{$user->email}> (user #{$user->id}). Sign in with this email and password.");

        return self::SUCCESS;
    }

    private function activeProgramLeaderExists(): bool
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))
            ->exists();
    }
}

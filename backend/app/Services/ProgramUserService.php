<?php

namespace App\Services;

use App\Http\Controllers\AuthController;
use App\Models\AuditTrail;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\AccountRejectedNotification;
use App\Notifications\AccountSetupNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProgramUserService
{
    /** Roles that can be assigned inside a project (one per person per project). */
    public const PROJECT_ROLES = [
        'project-leader',
        'research-assistant',
        'researcher',
        'finance-admin',
    ];

    /** Roles a Program Leader can grant to a directly-created account. */
    public const ACCOUNT_ROLES = [
        'program-leader',
        'project-leader',
        'research-assistant',
        'researcher',
        'finance-admin',
    ];

    public const ROLE_LABELS = [
        'program-leader' => 'Program Leader',
        'project-leader' => 'Project Leader',
        'research-assistant' => 'Research Assistant',
        'researcher' => 'Researcher',
        'finance-admin' => 'Finance/Admin Staff',
    ];

    public function pendingRegistrations(): Collection
    {
        return User::query()
            ->whereNull('role_id')
            ->whereNull('registration_rejected_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (User $user) => $this->pendingPayload($user))
            ->values();
    }

    public function decideRegistration(
        User $user,
        string $decision,
        ?string $roleSlug,
        ?string $remarks,
        User $actor
    ): User {
        if ($decision === 'rejected' && trim((string) $remarks) === '') {
            throw ValidationException::withMessages(['remarks' => 'A reason for rejection is required.']);
        }

        if ($decision === 'approved' && ! in_array($roleSlug, self::PROJECT_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Choose a valid role for this account.']);
        }

        $role = $decision === 'approved'
            ? Role::where('slug', $roleSlug)->firstOrFail()
            : null;

        DB::transaction(function () use ($user, $decision, $role, $remarks, $actor) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPendingRegistration()) {
                abort(409, 'This registration has already been reviewed.');
            }

            $oldValues = $locked->only(['role_id', 'is_active', 'registration_rejected_at', 'registration_rejection_reason']);

            if ($decision === 'approved') {
                $locked->update([
                    'role_id' => $role->id,
                    'is_active' => true,
                    'registration_reviewed_by' => $actor->id,
                    'registration_rejected_at' => null,
                    'registration_rejection_reason' => null,
                ]);
            } else {
                $locked->update([
                    'role_id' => null,
                    'is_active' => false,
                    'registration_reviewed_by' => $actor->id,
                    'registration_rejected_at' => now(),
                    'registration_rejection_reason' => trim((string) $remarks),
                ]);
            }

            AuditTrail::record(
                $actor,
                $decision === 'approved' ? 'approved' : 'rejected',
                $locked,
                $oldValues,
                $locked->fresh()->only(['role_id', 'is_active', 'registration_rejected_at', 'registration_rejection_reason']),
                $remarks
            );
        });

        $fresh = $user->fresh()->load('primaryRole');

        if ($decision === 'approved') {
            Notification::send($fresh, new AccountApprovedNotification($fresh, $role->name));
        } else {
            Notification::send($fresh, new AccountRejectedNotification($fresh, trim((string) $remarks)));
        }

        return $fresh;
    }

    public function addUser(array $data, User $actor): User
    {
        $role = Role::where('slug', $data['role'])->firstOrFail();
        $email = strtolower(trim($data['email']));
        $employeeId = strtoupper(trim($data['employee_id']));

        $user = DB::transaction(function () use ($data, $role, $email, $employeeId, $actor) {
            $user = User::create([
                'name' => trim($data['first_name'] . ' ' . $data['last_name']),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $email,
                'employee_id' => $employeeId,
                'position' => $data['position'],
                'college' => $data['college'] ?? null,
                'department' => $data['department'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                // The initial password is random and never shown or emailed.
                // The account holder sets their own password via the setup link.
                'password' => Hash::make(Str::random(40)),
                'role_id' => $role->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            AuditTrail::record(
                $actor,
                'created',
                $user,
                [],
                $user->only(['name', 'email', 'employee_id', 'position', 'college', 'department', 'role_id', 'is_active'])
            );

            return $user;
        });

        $setupUrl = $this->createPasswordSetupLink($user);

        Notification::send($user, new AccountSetupNotification($user, $setupUrl));

        return $user->fresh()->load('primaryRole');
    }

    public function users(?int $projectId = null): Collection
    {
        return User::query()
            ->whereNotNull('role_id')
            ->with([
                'primaryRole',
                'projectUsers.project',
                'projectUsers.role',
            ])
            ->when($projectId, function ($query) use ($projectId) {
                $query->whereHas('projectUsers', fn ($assignment) => $assignment->where('project_id', $projectId));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->userPayload($user))
            ->values();
    }

    public function assign(User $user, Project $project, string $roleSlug, User $actor): ProjectUser
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();

        return DB::transaction(function () use ($user, $project, $role, $actor) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $lockedUser->is_active) {
                abort(422, 'Deactivated users cannot be assigned to projects.');
            }

            $existing = ProjectUser::query()
                ->where('user_id', $lockedUser->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                abort(422, 'This user already has a role on that project. Edit their role instead.');
            }

            $assignment = ProjectUser::create([
                'user_id' => $lockedUser->id,
                'project_id' => $project->id,
                'role_id' => $role->id,
                'assigned_at' => now(),
            ]);

            AuditTrail::record($actor, 'assignment_created', $lockedUser, [], [
                'project_id' => $project->id,
                'project' => $project->title,
                'role' => $role->slug,
                'role_label' => $role->name,
            ]);

            return $assignment;
        });
    }

    public function updateAssignment(User $user, Project $project, string $roleSlug, User $actor, ?string $reason = null): ProjectUser
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();

        return DB::transaction(function () use ($user, $project, $role, $actor, $reason) {
            $assignment = ProjectUser::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldRole = Role::find($assignment->role_id);

            $assignment->update(['role_id' => $role->id]);

            AuditTrail::record($actor, 'assignment_updated', $user, [
                'project_id' => $project->id,
                'role' => $oldRole?->slug,
                'role_label' => $oldRole?->name,
            ], [
                'project_id' => $project->id,
                'role' => $role->slug,
                'role_label' => $role->name,
            ], $reason);

            return $assignment->fresh();
        });
    }

    public function removeAssignment(User $user, Project $project, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $project, $actor, $reason) {
            $assignment = ProjectUser::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $role = Role::find($assignment->role_id);

            $assignment->delete();

            AuditTrail::record($actor, 'assignment_removed', $user, [
                'project_id' => $project->id,
                'project' => $project->title,
                'role' => $role?->slug,
                'role_label' => $role?->name,
            ], [], $reason);
        });
    }

    public function setActive(User $user, bool $isActive, ?string $reason, User $actor): User
    {
        if (! $isActive && (int) $user->id === (int) $actor->id) {
            abort(422, 'You cannot deactivate your own account.');
        }

        return DB::transaction(function () use ($user, $isActive, $reason, $actor) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldValues = $locked->only(['is_active']);

            if (! $isActive) {
                $this->ensureNotLastProgramLeader($locked);
            }

            $locked->update(['is_active' => $isActive]);

            AuditTrail::record(
                $actor,
                $isActive ? 'activated' : 'deactivated',
                $locked,
                $oldValues,
                $locked->fresh()->only(['is_active']),
                $reason
            );

            return $locked->fresh()->load('primaryRole');
        });
    }

    public function updatePrimaryRole(User $user, string $roleSlug, ?string $reason, User $actor): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();

        return DB::transaction(function () use ($user, $role, $reason, $actor) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldRole = Role::find($locked->role_id);

            if ((int) $locked->id === (int) $actor->id && $oldRole?->slug !== $role->slug) {
                $message = $oldRole?->slug === 'program-leader'
                    ? 'You cannot change your own Program Leader role.'
                    : 'You cannot change your own role.';
                abort(422, $message);
            }

            if ($oldRole?->slug === $role->slug) {
                return $locked;
            }

            if ($oldRole?->slug === 'program-leader') {
                $this->ensureNotLastProgramLeader($locked);
            }

            $locked->update(['role_id' => $role->id]);

            AuditTrail::record(
                $actor,
                'role_changed',
                $locked,
                ['role' => $oldRole?->slug, 'role_label' => $oldRole?->name],
                ['role' => $role->slug, 'role_label' => $role->name],
                $reason
            );

            return $locked->fresh()->load('primaryRole');
        });
    }

    public function createPasswordSetupLink(User $user): string
    {
        $token = Str::random(60);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $user->email],
            ['token' => $token, 'created_at' => now()]
        );

        $base = rtrim(config('app.frontend_url') ?: config('app.url', ''), '/');

        return $base . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);
    }

    protected function ensureNotLastProgramLeader(User $user): void
    {
        if (! $user->is_active || $user->primaryRole?->slug !== 'program-leader') {
            return;
        }

        $otherLeaders = User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->whereHas('primaryRole', fn ($query) => $query->where('slug', 'program-leader'))
            ->count();

        if ($otherLeaders === 0) {
            abort(422, 'At least one active Program Leader is required.');
        }
    }

    protected function pendingPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'position' => $user->position,
            'college' => $user->college,
            'department' => $user->department,
            'contact_number' => $user->contact_number,
            'status' => 'Pending',
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'position' => $user->position,
            'college' => $user->college,
            'department' => $user->department,
            'role' => $user->primaryRole?->slug
                ? (AuthController::SLUG_TO_CODE[$user->primaryRole->slug] ?? null)
                : null,
            'role_slug' => $user->primaryRole?->slug,
            'role_label' => $user->primaryRole?->name,
            'status' => $user->is_active ? 'Active' : 'Inactive',
            'is_active' => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'assignments' => $user->projectUsers->map(fn (ProjectUser $assignment) => [
                'project_id' => $assignment->project_id,
                'project' => $assignment->project?->title,
                'project_code' => $assignment->project?->code,
                'role_slug' => $assignment->role?->slug,
                'role_label' => $assignment->role?->name,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}

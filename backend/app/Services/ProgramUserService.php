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
use Illuminate\Support\Facades\Schema;
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

    /**
     * Approve (with the account role and, optionally, project assignments)
     * or reject a pending registration. Approval is ONE transaction: if any
     * assignment row breaks a rule, nothing is saved and the error names the
     * row (assignments.N.project_id). One audit entry either way; the
     * applicant is emailed after the commit (a mailer failure is only logged).
     *
     * @param array<int, array{project_id: int|string, role: string}> $assignments
     */
    public function decideRegistration(
        User $user,
        string $decision,
        ?string $roleSlug,
        ?string $remarks,
        User $actor,
        array $assignments = []
    ): User {
        if ($decision === 'rejected' && trim((string) $remarks) === '') {
            throw ValidationException::withMessages(['remarks' => 'A reason for rejection is required.']);
        }

        if ($decision === 'approved' && ! in_array($roleSlug, self::ACCOUNT_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Choose a valid role for this account.']);
        }

        $assignments = $decision === 'approved' ? array_values($assignments) : [];

        if ($roleSlug === 'program-leader' && $assignments !== []) {
            throw ValidationException::withMessages([
                'assignments' => 'A Program Leader oversees every project; remove the project rows.',
            ]);
        }

        // Resolve every row up front; a second row for the same project is
        // named here (one person, one role per project).
        $rows = [];
        $seen = [];
        foreach ($assignments as $index => $row) {
            $project = Project::find($row['project_id'] ?? null);
            if (! $project) {
                throw ValidationException::withMessages(["assignments.$index.project_id" => 'Choose a project.']);
            }
            if (isset($seen[$project->id])) {
                throw ValidationException::withMessages([
                    "assignments.$index.project_id" => 'This project is already in another row. One person has one role per project.',
                ]);
            }
            $seen[$project->id] = true;
            // The role on a project is the account role; an older client may
            // still send one, which must match.
            $requested = $row['role'] ?? null;
            if ($requested !== null && $requested !== '' && $requested !== $roleSlug) {
                throw ValidationException::withMessages([
                    "assignments.$index.role" => "A person's role on a project is always their account role.",
                ]);
            }
            $rows[$index] = $project;
        }

        $role = $decision === 'approved'
            ? Role::where('slug', $roleSlug)->firstOrFail()
            : null;

        AuditLogger::transaction(function () use ($user, $decision, $role, $remarks, $actor, $rows) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPendingRegistration()) {
                abort(409, 'This registration has already been reviewed.');
            }

            $oldValues = $locked->only(['role_id', 'is_active', 'registration_rejected_at', 'registration_rejection_reason']);
            $extra = [];

            if ($decision === 'approved') {
                $locked->update([
                    'role_id' => $role->id,
                    'is_active' => true,
                    'registration_reviewed_by' => $actor->id,
                    'registration_rejected_at' => null,
                    'registration_rejection_reason' => null,
                ]);

                // Same rules as "Assign to project"; a broken rule rolls
                // back the whole approval and names the row.
                $logged = [];
                foreach ($rows as $index => $project) {
                    try {
                        $assignmentRole = $this->createAssignmentRow($locked, $project)->role;
                    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                        throw ValidationException::withMessages(["assignments.$index.project_id" => $e->getMessage()]);
                    }
                    $logged[] = [
                        'project_id' => $project->id,
                        'project' => $project->title,
                        'role' => $assignmentRole->slug,
                        'role_label' => self::ROLE_LABELS[$assignmentRole->slug] ?? $assignmentRole->name,
                    ];
                }

                $extra = [
                    'role' => $role->slug,
                    'role_label' => self::ROLE_LABELS[$role->slug] ?? $role->name,
                    'assignments' => $logged,
                ];
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
                $locked->fresh()->only(['role_id', 'is_active', 'registration_rejected_at', 'registration_rejection_reason']) + $extra,
                $remarks
            );
        });

        $fresh = $user->fresh()->load('primaryRole', 'projectUsers.project', 'projectUsers.role');

        // The request is handled: its bell entry is done for every Program Leader.
        \App\Notifications\NewRegistrationPendingNotification::resolveFor($fresh);

        $this->sendDecisionEmail($fresh, $decision === 'approved'
            ? new AccountApprovedNotification(
                $fresh,
                self::ROLE_LABELS[$role->slug] ?? $role->name,
                array_map(
                    fn (Project $project) => $project->title . ' (' . (self::ROLE_LABELS[$role->slug] ?? $role->name) . ')',
                    array_values($rows)
                )
            )
            : new AccountRejectedNotification($fresh, trim((string) $remarks)));

        return $fresh;
    }

    /** The decision is saved already; a mailer failure is logged, never thrown. */
    protected function sendDecisionEmail(User $user, \Illuminate\Notifications\Notification $notification): void
    {
        try {
            Notification::send($user, $notification);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Registration decision email could not be sent.', [
                'user_id' => $user->id,
                'notification' => class_basename($notification),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function addUser(array $data, User $actor): User
    {
        $role = Role::where('slug', $data['role'])->firstOrFail();
        $email = strtolower(trim($data['email']));
        $employeeId = strtoupper(trim($data['employee_id']));

        $user = AuditLogger::transaction(function () use ($data, $role, $email, $employeeId, $actor) {
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

    public const PER_PAGE = 25;

    /**
     * One row per person (approved accounts only), filtered and paged on the
     * server. Relations are eager-loaded, so a page costs a fixed number of
     * queries. With ?user= on the first page, the page is extended until it
     * contains that person so the list can scroll to them.
     *
     * @param array{q?: ?string, role?: ?string, project?: ?int, status?: ?string, user?: ?int, page?: ?int} $filters
     */
    public function directory(array $filters, User $actor): array
    {
        $status = $filters['status'] ?? (! empty($filters['user']) ? 'all' : 'active');
        $term = trim((string) ($filters['q'] ?? ''));

        $query = User::query()
            ->whereNotNull('role_id')
            ->when($term !== '', function ($query) use ($term) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('employee_id', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn ($query, $slug) => $query->whereHas('primaryRole', fn ($r) => $r->where('slug', $slug)))
            // Program Leaders oversee every project, so they match any project.
            ->when($filters['project'] ?? null, fn ($query, $projectId) => $query->where(fn ($w) => $w
                ->whereHas('projectUsers', fn ($a) => $a->where('project_id', $projectId))
                ->orWhereHas('primaryRole', fn ($r) => $r->where('slug', 'program-leader'))))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false));

        $total = (clone $query)->count();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $limit = self::PER_PAGE;
        $focusFound = null;

        if (! empty($filters['user'])) {
            $ids = (clone $query)->orderBy('name')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id);
            $index = $ids->search((int) $filters['user'], true);
            $focusFound = $index !== false;
            if ($focusFound && $page === 1) {
                $limit = (int) (ceil(($index + 1) / self::PER_PAGE) * self::PER_PAGE);
            }
        }

        $users = $query
            ->with(['primaryRole', 'projectUsers.project', 'projectUsers.role'])
            ->orderBy('name')
            ->orderBy('id')
            ->skip($offset)
            ->take($limit)
            ->get();

        return [
            'users' => $users->map(fn (User $user) => $this->directoryRow($user, $actor))->values()->all(),
            'meta' => [
                'total' => $total,
                'page' => (int) (($offset + $limit) / self::PER_PAGE),
                'per_page' => self::PER_PAGE,
                'has_more' => $offset + $users->count() < $total,
                'status' => $status,
                'focus_found' => $focusFound,
            ],
        ];
    }

    /** Active, non-Program-Leader accounts for the "Assign to project" dialog. */
    public function assignableUsers(): array
    {
        return User::query()
            ->whereNotNull('role_id')
            ->where('is_active', true)
            ->whereDoesntHave('primaryRole', fn ($r) => $r->where('slug', 'program-leader'))
            ->orderBy('name')
            ->with('primaryRole')
            ->get(['id', 'name', 'email', 'is_active', 'role_id'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => true,
                'role_slug' => $user->primaryRole?->slug,
                'role_label' => self::ROLE_LABELS[$user->primaryRole?->slug] ?? $user->primaryRole?->name,
            ])
            ->values()
            ->all();
    }

    protected function directoryRow(User $user, User $actor): array
    {
        $slug = $user->primaryRole?->slug;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'position' => $user->position,
            'role' => $slug ? (AuthController::SLUG_TO_CODE[$slug] ?? null) : null,
            'role_slug' => $slug,
            'role_label' => self::ROLE_LABELS[$slug] ?? $user->primaryRole?->name,
            'status' => $user->is_active ? 'Active' : 'Inactive',
            'is_active' => (bool) $user->is_active,
            'is_self' => (int) $user->id === (int) $actor->id,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            // Program Leaders oversee all projects; any assignment rows they
            // still have are kept (and returned) but not shown as their scope.
            'program_wide' => $slug === 'program-leader',
            'assignments' => $user->projectUsers
                ->filter(fn (ProjectUser $assignment) => $assignment->project !== null)
                ->sortBy(fn (ProjectUser $assignment) => $assignment->project->title)
                ->map(fn (ProjectUser $assignment) => [
                    'project_id' => (int) $assignment->project_id,
                    'project' => $assignment->project->title,
                    'project_code' => $assignment->project->code,
                    'role_slug' => $assignment->role?->slug,
                    'role_label' => self::ROLE_LABELS[$assignment->role?->slug] ?? $assignment->role?->name,
                    // Older data: the role here differs from the account role
                    // (never changed automatically; "Fix" sets it).
                    'role_mismatch' => $slug !== 'program-leader' && $assignment->role?->slug !== $slug,
                    // Pay rate on this project (null = not set); edited with PATCH /projects/{id}/pay-rates/{user}.
                    'monthly_rate' => $assignment->monthly_rate !== null ? (float) $assignment->monthly_rate : null,
                    'allowance' => $assignment->allowance !== null ? (float) $assignment->allowance : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Edit name, position/office, email and employee ID. One audit entry with
     * only the fields that changed.
     */
    public function updateDetails(User $user, array $data, User $actor): User
    {
        return AuditLogger::transaction(function () use ($user, $data, $actor) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $fields = ['name', 'position', 'email', 'employee_id', 'contact_number', 'college', 'department'];
            $old = $locked->only($fields);

            $locked->update(array_intersect_key($data, array_flip($fields)));

            AuditLogger::write('updated', $locked, $old, $locked->fresh()->only($fields), $actor);

            return $locked->fresh()->load('primaryRole');
        });
    }

    /** Sign the person out everywhere (database sessions and API tokens). */
    protected function revokeSessions(User $user): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        if (Schema::hasTable('personal_access_tokens')) {
            $user->tokens()->delete();
        }
    }

    /**
     * Assign the person to a project. Their role there is always their
     * account role; $roleSlug (optional, from older clients) must match it.
     */
    public function assign(User $user, Project $project, ?string $roleSlug, User $actor): ProjectUser
    {
        return AuditLogger::transaction(function () use ($user, $project, $roleSlug, $actor) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $assignment = $this->createAssignmentRow($lockedUser, $project, $roleSlug);
            $role = $assignment->role;

            AuditTrail::record($actor, 'assignment_created', $lockedUser, [], [
                'project_id' => $project->id,
                'project' => $project->title,
                'role' => $role->slug,
                'role_label' => $role->name,
            ]);

            return $assignment;
        });
    }

    /**
     * The assignment rules, shared by "Assign to project" and the one-step
     * registration approval. A person's role on a project is always their
     * account role, so the role is never chosen here: the account must be
     * active and approved, Program Leaders (program-wide) take no project
     * rows, one person has one row per project, and a project has one
     * Project Leader. $requestedRole, when given, must equal the account
     * role. Call inside a transaction with the user row locked; aborts 422.
     */
    protected function createAssignmentRow(User $lockedUser, Project $project, ?string $requestedRole = null): ProjectUser
    {
        if (! $lockedUser->is_active) {
            abort(422, 'Deactivated users cannot be assigned to projects.');
        }

        $role = $this->projectRoleFor($lockedUser);

        if ($requestedRole !== null && $requestedRole !== '' && $requestedRole !== $role->slug) {
            abort(422, "A person's role on a project is always their account role (" . $this->roleLabel($role) . ').');
        }

        $existing = ProjectUser::query()
            ->where('user_id', $lockedUser->id)
            ->where('project_id', $project->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            abort(422, 'This user already has a role on that project. Edit their role instead.');
        }

        if ($role->slug === 'project-leader') {
            $this->ensureNoOtherLeader($project, $lockedUser);
        }

        return ProjectUser::create([
            'user_id' => $lockedUser->id,
            'project_id' => $project->id,
            'role_id' => $role->id,
            'assigned_at' => now(),
        ])->setRelation('role', $role);
    }

    /** The role the person holds on every project: their account role (422 when it cannot go on a project). */
    protected function projectRoleFor(User $user): Role
    {
        $role = $user->role_id ? Role::find($user->role_id) : null;

        if (! $role) {
            abort(422, 'Approve the account and give it a role before assigning it to a project.');
        }

        if ($role->slug === 'program-leader') {
            abort(422, 'Program Leaders oversee every project and are not assigned to one.');
        }

        if (! in_array($role->slug, self::PROJECT_ROLES, true)) {
            abort(422, 'This account role cannot be assigned to a project.');
        }

        return $role;
    }

    /** One Project Leader per project: block a second one, naming the project. */
    protected function ensureNoOtherLeader(Project $project, User $user): void
    {
        $other = ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('user_id', '!=', $user->id)
            ->whereHas('role', fn ($query) => $query->where('slug', 'project-leader'))
            ->with('user:id,name')
            ->first();

        if ($other) {
            abort(422, $project->title . ' already has a Project Leader' . ($other->user ? ' (' . $other->user->name . ')' : '')
                . '. A project has one Project Leader; change or remove that assignment first.');
        }
    }

    protected function roleLabel(?Role $role): string
    {
        return $role ? (self::ROLE_LABELS[$role->slug] ?? $role->name) : 'No role';
    }

    /**
     * "Fix": set the person's role on a project to their account role (the
     * only role a project row may hold). Used for older rows whose role
     * differs. $roleSlug, if sent, must equal the account role. Legacy or
     * seeded data may hold more than one row for the same person and
     * project; they are merged into ONE row here (the oldest row is kept, so
     * its pay rate stays). One audit entry per change; nothing when nothing
     * changes.
     */
    public function updateAssignment(User $user, Project $project, ?string $roleSlug, User $actor, ?string $reason = null): ProjectUser
    {
        $assignment = $this->fixAssignmentRole($user, $project, $roleSlug, $actor, $reason);
        $this->rerouteAfterRoleChange($user, $actor);

        return $assignment;
    }

    private function fixAssignmentRole(User $user, Project $project, ?string $roleSlug, User $actor, ?string $reason): ProjectUser
    {
        return AuditLogger::transaction(function () use ($user, $project, $roleSlug, $actor, $reason) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $role = $this->projectRoleFor($lockedUser);

            if ($roleSlug !== null && $roleSlug !== '' && $roleSlug !== $role->slug) {
                abort(422, "A person's role on a project is always their account role (" . $this->roleLabel($role) . ').');
            }

            $rows = $this->assignmentRows($user, $project);
            $assignment = $rows->first();
            $oldRoles = $rows->map(fn (ProjectUser $row) => $row->role?->slug)->filter()->values()->all();
            $oldRole = $assignment->role;

            // Same single role: nothing changes and nothing is logged.
            if ($rows->count() === 1 && (int) $assignment->role_id === (int) $role->id) {
                return $assignment;
            }

            if (in_array('project-leader', $oldRoles, true) && $role->slug !== 'project-leader') {
                $this->ensureNotOnlyProjectLeader($project, $user);
            }

            if ($role->slug === 'project-leader') {
                $this->ensureNoOtherLeader($project, $user);
            }

            $rows->slice(1)->each(fn (ProjectUser $extra) => $extra->delete());
            $assignment->update(['role_id' => $role->id]);

            AuditTrail::record($actor, 'assignment_updated', $user, array_filter([
                'project_id' => $project->id,
                'role' => $oldRole?->slug,
                'role_label' => $oldRole?->name,
                // Only when duplicate rows were merged.
                'roles' => count($oldRoles) > 1 ? $oldRoles : null,
            ], fn ($value) => $value !== null), [
                'project_id' => $project->id,
                'role' => $role->slug,
                'role_label' => $role->name,
            ], $reason);

            return $assignment->fresh();
        });
    }

    /** Remove the person from the project: every row they have there goes (one audit entry). */
    public function removeAssignment(User $user, Project $project, User $actor, ?string $reason = null): void
    {
        AuditLogger::transaction(function () use ($user, $project, $actor, $reason) {
            $rows = $this->assignmentRows($user, $project);
            $roles = $rows->map(fn (ProjectUser $row) => $row->role?->slug)->filter()->values()->all();
            $role = $rows->first()->role;

            if (in_array('project-leader', $roles, true)) {
                $this->ensureNotOnlyProjectLeader($project, $user);
            }

            // Only the assignment rows go. Expenses, approvals and audit
            // entries keep pointing at the person.
            $rows->each(fn (ProjectUser $row) => $row->delete());

            AuditTrail::record($actor, 'assignment_removed', $user, array_filter([
                'project_id' => $project->id,
                'project' => $project->title,
                'role' => $role?->slug,
                'role_label' => $role?->name,
                'roles' => count($roles) > 1 ? $roles : null,
            ], fn ($value) => $value !== null), [], $reason);
        });
    }

    /**
     * Every assignment row of the person on the project, oldest first,
     * locked (404 when there is none).
     *
     * @return \Illuminate\Support\Collection<int, ProjectUser>
     */
    protected function assignmentRows(User $user, Project $project)
    {
        $rows = ProjectUser::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->with('role')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        abort_if($rows->isEmpty(), 404, 'This person is not assigned to that project.');

        return $rows;
    }

    public function setActive(User $user, bool $isActive, ?string $reason, User $actor): User
    {
        if (! $isActive && (int) $user->id === (int) $actor->id) {
            abort(422, 'You cannot deactivate your own account.');
        }

        return AuditLogger::transaction(function () use ($user, $isActive, $reason, $actor) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldValues = $locked->only(['is_active']);

            // Already in the requested state: nothing changes, nothing is logged.
            if ((bool) $locked->is_active === $isActive) {
                return $locked->load('primaryRole');
            }

            if (! $isActive) {
                $this->ensureNotLastProgramLeader($locked);
            }

            $locked->update(['is_active' => $isActive]);

            if (! $isActive) {
                $this->revokeSessions($locked);
            }

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

        $updated = AuditLogger::transaction(function () use ($user, $role, $reason, $actor) {
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

            // A person's role on a project is their account role, so every
            // project row follows the new role in this same transaction.
            $moved = $this->moveAssignmentsToRole($locked, $role);

            $locked->update(['role_id' => $role->id]);

            AuditTrail::record(
                $actor,
                'role_changed',
                $locked,
                ['role' => $oldRole?->slug, 'role_label' => $oldRole?->name],
                ['role' => $role->slug, 'role_label' => $role->name] + ($moved ? ['assignments' => $moved] : []),
                $reason
            );

            return $locked->fresh()->load('primaryRole');
        });

        $this->rerouteAfterRoleChange($updated, $actor);

        return $updated;
    }

    /**
     * Their items waiting for the Project Leader step may now have no
     * reviewer but themselves (they became the Project Leader): move them
     * on (LeaderStepRouter). After the commit, so a failure never undoes
     * the role change.
     */
    protected function rerouteAfterRoleChange(User $user, User $actor): void
    {
        $projectIds = ProjectUser::where('user_id', $user->id)->pluck('project_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($projectIds === []) {
            return;
        }

        try {
            app(LeaderStepRouter::class)->reroute($actor, $projectIds);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Set every project row of the person to $role (inside the caller's
     * transaction). Blocks, naming the project, when that would give a
     * project two Project Leaders or leave an ongoing project without one.
     * A Program Leader takes no project rows, so those must be removed first.
     *
     * @return array<int, array{project_id: int, project: ?string, from: ?string, to: string}>
     */
    protected function moveAssignmentsToRole(User $lockedUser, Role $role): array
    {
        $rows = ProjectUser::query()
            ->where('user_id', $lockedUser->id)
            ->with(['role', 'project'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        if ($role->slug === 'program-leader') {
            abort(422, 'Remove ' . $lockedUser->name . "'s project assignments first. A Program Leader oversees every project and is not assigned to one.");
        }

        foreach ($rows->groupBy('project_id') as $projectRows) {
            $project = $projectRows->first()->project;
            if (! $project) {
                continue;
            }
            $wasLeader = $projectRows->contains(fn (ProjectUser $row) => $row->role?->slug === 'project-leader');

            if ($role->slug === 'project-leader') {
                $this->ensureNoOtherLeader($project, $lockedUser);
            } elseif ($wasLeader) {
                $this->ensureNotOnlyProjectLeader($project, $lockedUser, true);
            }
        }

        $moved = [];
        foreach ($rows as $row) {
            if ((int) $row->role_id === (int) $role->id) {
                continue;
            }
            $moved[] = [
                'project_id' => (int) $row->project_id,
                'project' => $row->project?->title,
                'from' => $row->role?->slug,
                'to' => $role->slug,
            ];
            $row->update(['role_id' => $role->id]);
        }

        return $moved;
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

    /**
     * An ongoing project must keep at least one Project Leader.
     */
    protected function ensureNotOnlyProjectLeader(Project $project, User $leaving, bool $nameProject = false): void
    {
        if (app(ProjectFinancialService::class)->derivedStatus($project) !== 'ongoing') {
            return;
        }

        $otherLeaders = ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('user_id', '!=', $leaving->id)
            ->whereHas('role', fn ($query) => $query->where('slug', 'project-leader'))
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->count();

        if ($otherLeaders === 0) {
            // One Project Leader per project: a replacement is chosen in the
            // project's set-up, which swaps the leader in one step.
            abort(422, ($nameProject ? $project->title : 'This project') . ' would be left without a Project Leader.'
                . " Choose the new Project Leader in the project's set-up first; it replaces this one.");
        }
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
            'contact_number' => $user->contact_number,
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
                'monthly_rate' => $assignment->monthly_rate !== null ? (float) $assignment->monthly_rate : null,
                'allowance' => $assignment->allowance !== null ? (float) $assignment->allowance : null,
            ])->values()->all(),
        ];
    }
}

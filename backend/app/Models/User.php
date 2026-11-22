<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Contact number: exactly 11 digits starting with 09 (PH mobile, e.g.
     * 09171234567). Same rule and message as the frontend
     * (lib/authValidation.js). "D": no trailing newline sneaks past "$".
     */
    public const CONTACT_NUMBER_REGEX = '/^09\d{9}$/D';
    public const CONTACT_NUMBER_MESSAGE = 'Enter an 11-digit mobile number starting with 09';

    /** Validation rules for a new or changed contact number. */
    public static function contactNumberRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:' . self::CONTACT_NUMBER_REGEX];
    }

    /** Messages for contactNumberRules(). */
    public static function contactNumberMessages(): array
    {
        return [
            'contact_number.string' => self::CONTACT_NUMBER_MESSAGE,
            'contact_number.regex' => self::CONTACT_NUMBER_MESSAGE,
        ];
    }

    protected $fillable = [
        'name', 'first_name', 'last_name', 'email', 'password', 'employee_id', 'contact_number', 'college', 'department', 'role_id',
        'position', 'email_verified_at', 'is_active', 'registration_reviewed_by', 'registration_rejected_at',
        'registration_rejection_reason', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'registration_rejected_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /**
     * Send the branded password-reset link through the custom notification.
     * The parent CanResetPassword trait supplies getEmailForPasswordReset().
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * A self-registered account is pending until a Program Leader approves or
     * rejects it. Rejected accounts stay on record for audit purposes.
     */
    public function isPendingRegistration(): bool
    {
        return $this->role_id === null && $this->registration_rejected_at === null;
    }

    // Projects this user is attached to, with role pivot data
    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_user')
            ->using(ProjectUser::class)
            ->withPivot('role_id', 'assigned_at')
            ->withTimestamps();
    }

    public function projectUsers()
    {
        return $this->hasMany(ProjectUser::class);
    }

    public function primaryRole()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function recordedExpenses()
    {
        return $this->hasMany(Expense::class, 'recorded_by');
    }

    public function verifiedExpenses()
    {
        return $this->hasMany(Expense::class, 'verified_by');
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class, 'personnel_id');
    }

    public function auditTrails()
    {
        return $this->hasMany(AuditTrail::class);
    }

    /*
     * "My projects" resolver: the ONE rule every page, API, badge count and
     * notification recipient lookup uses. A person holds role R on project P
     * when a project_user row (P, person, R) exists, which is exactly what
     * the Program Leader sets in Projects and in Users and roles. A person may
     * have more than one row on a project (legacy and seeded data); each row
     * counts, so no row is ever "hidden" behind another one.
     */

    /**
     * Every role slug this user holds on the project, oldest row first.
     *
     * @return array<int, string>
     */
    public function rolesOn(Project|int $project): array
    {
        $projectId = $project instanceof Project ? $project->getKey() : $project;

        return $this->projectUsers()
            ->where('project_id', $projectId)
            ->with('role')
            ->orderBy('id')
            ->get()
            ->map(fn (ProjectUser $row) => $row->role?->slug)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The one role to show for this project: the user's account role when
     * they hold it there, otherwise their oldest row's role. Permission checks
     * use hasRoleOn() instead, so a second row never takes a role away.
     */
    public function roleOn(Project|int $project): ?string
    {
        return self::displayRole($this->rolesOn($project), $this->primaryRole?->slug);
    }

    /** @param array<int, string> $roles */
    public static function displayRole(array $roles, ?string $accountRole): ?string
    {
        if ($accountRole !== null && in_array($accountRole, $roles, true)) {
            return $accountRole;
        }

        return $roles[0] ?? null;
    }

    /** True when the user holds (any of) the role(s) on the project. */
    public function hasRoleOn(Project|int $project, string|array $roleSlugs): bool
    {
        return array_intersect((array) $roleSlugs, $this->rolesOn($project)) !== [];
    }

    /**
     * "My projects" for one role: the ids of the projects where the user
     * holds that project role.
     *
     * @return array<int, int>
     */
    public function projectIdsAs(string $roleSlug): array
    {
        return $this->projectUsers()
            ->whereHas('role', fn ($query) => $query->where('slug', $roleSlug))
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function isProgramLeader(): bool
    {
        return $this->primaryRole?->slug === 'program-leader';
    }

    /**
     * Project scoping: the Program Leader sees every project; everyone else
     * only the projects they are assigned to. Null means "all projects".
     *
     * @return array<int, int>|null
     */
    public function accessibleProjectIds(): ?array
    {
        if ($this->isProgramLeader()) {
            return null;
        }

        return $this->projectUsers()->pluck('project_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Personnel pay (honoraria per person, payroll rows, the payroll register
     * and payroll files): the Program Leader, and the project's Project
     * Leader, Finance/Admin and Research Assistants. A Researcher sees
     * payroll only as totals (budget, transactions, Form B), never per person.
     */
    public function canSeePersonnelPay(Project|int $project): bool
    {
        if ($this->isProgramLeader()) {
            return true;
        }

        return $this->hasRoleOn($project, ['project-leader', 'finance-admin', 'research-assistant']);
    }

    public function canAccessProject(Project|int $project): bool
    {
        if ($this->isProgramLeader()) {
            return true;
        }

        $projectId = $project instanceof Project ? $project->getKey() : $project;

        return $this->projectUsers()->where('project_id', $projectId)->exists();
    }
}

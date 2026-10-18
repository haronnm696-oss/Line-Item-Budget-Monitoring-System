<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'employee_id', 'email', 'password', 'role_id',
        'position', 'college_department', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

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

    /**
     * Resolve this user's role slug for a given project.
     * This is the core RBAC lookup: role is per-project, not global.
     */
    public function roleOn(Project $project): ?string
    {
        $pivot = $this->projectUsers()
            ->where('project_id', $project->id)
            ->with('role')
            ->first();

        return $pivot?->role?->slug;
    }

    public function hasRoleOn(Project $project, string $roleSlug): bool
    {
        return $this->roleOn($project) === $roleSlug;
    }
}

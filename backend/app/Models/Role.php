<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    public function projectUsers()
    {
        return $this->hasMany(ProjectUser::class);
    }

    /** Convenience: role-based checks, e.g. $role->isFinance() */
    public function isFinance(): bool
    {
        return $this->slug === 'finance-admin';
    }

    /** Convenience: role-based checks, e.g. $role->isProgramLeader() */
    public function isProgramLeader(): bool
    {
        return $this->slug === 'program-leader';
    }

    /** Convenience: role-based checks, e.g. $role->isProjectLeader() */
    public function isProjectLeader(): bool
    {
        return $this->slug === 'project-leader';
    }

    /** Convenience: role-based checks, e.g. $role->isResearchAssistant() */
    public function isResearchAssistant(): bool
    {
        return $this->slug === 'research-assistant';
    }

    /** Convenience: role-based checks, e.g. $role->isResearcher() */
    public function isResearcher(): bool
    {
        return $this->slug === 'researcher';
    }
}

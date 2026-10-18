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
    public function is(string $slug): bool
    {
        return $this->slug === $slug;
    }
}

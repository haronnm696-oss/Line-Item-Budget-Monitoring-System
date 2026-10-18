<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Custom pivot model (rather than a plain array pivot) so we can
 * eager-load ->role and ->user/->project directly off an assignment,
 * which the RBAC middleware and admin UI both need.
 */
class ProjectUser extends Pivot
{
    protected $table = 'project_user';

    public $incrementing = true;

    protected $fillable = ['project_id', 'user_id', 'role_id', 'assigned_at'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}

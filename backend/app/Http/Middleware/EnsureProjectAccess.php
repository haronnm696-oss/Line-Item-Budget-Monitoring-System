<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Project scoping for every route that names a project or a project's record
 * ({project}, {expense}, {approval}, {tranche}, {liquidation}): only the
 * Program Leader or a user assigned to that project gets through; everyone
 * else receives 403. Routes without such a parameter pass unchanged.
 */
class EnsureProjectAccess
{
    private const PROJECT_PARAMETERS = ['project', 'expense', 'approval', 'tranche', 'liquidation'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $route = $request->route();

        if ($user && $route) {
            foreach (self::PROJECT_PARAMETERS as $name) {
                $value = $route->parameter($name);
                if (! $value instanceof Model) {
                    continue;
                }

                $projectId = $value instanceof Project ? $value->getKey() : $value->getAttribute('project_id');

                if ($projectId !== null && ! $user->canAccessProject((int) $projectId)) {
                    abort(403, 'You are not assigned to this project.');
                }
            }
        }

        return $next($request);
    }
}

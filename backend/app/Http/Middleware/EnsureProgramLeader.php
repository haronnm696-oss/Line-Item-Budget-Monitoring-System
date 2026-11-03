<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureProgramLeader
{
    public function handle(Request $request, Closure $next)
    {
        $slug = $request->user()?->primaryRole?->slug;

        abort_unless(
            $slug === 'program-leader',
            403,
            'Only Program Leaders can access this resource.'
        );

        return $next($request);
    }
}

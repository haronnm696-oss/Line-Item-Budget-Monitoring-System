<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A deactivated account loses access on its very next request, even if it
 * still holds a session (deactivation also deletes database sessions and
 * tokens; this covers any other session driver).
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'This account is deactivated. Contact your administrator.',
            ], 401);
        }

        return $next($request);
    }
}

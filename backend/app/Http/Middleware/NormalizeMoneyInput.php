<?php

namespace App\Http\Middleware;

use App\Support\MoneyInput;
use Closure;
use Illuminate\Http\Request;

/**
 * Strips ₱, commas and digit-group spaces from peso fields before any
 * controller validates them ("3,178,376.00" -> "3178376.00"), for JSON and
 * multipart requests alike. See App\Support\MoneyInput.
 */
class NormalizeMoneyInput
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            // merge() writes to the JSON body or the form input, whichever the request has.
            $request->merge(MoneyInput::normalizeInput($request->all()));
        }

        return $next($request);
    }
}

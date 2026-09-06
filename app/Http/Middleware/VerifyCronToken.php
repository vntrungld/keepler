<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints an external scheduler calls, since they run work that
 * would otherwise require an authenticated session.
 */
class VerifyCronToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.cron.token');
        $provided = $request->header('X-Cron-Token');

        // An unset token rejects everything rather than matching an empty
        // header, so a missing CRON_TOKEN cannot leave the endpoint open.
        if (! is_string($expected) || $expected === '' || ! is_string($provided)) {
            abort(403);
        }

        if (! hash_equals($expected, $provided)) {
            abort(403);
        }

        return $next($request);
    }
}

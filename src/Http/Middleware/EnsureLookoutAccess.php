<?php

namespace Lookout\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Lookout\Lookout;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404 while Lookout is off (the page does not exist), 403 for anyone the viewLookout gate refuses.
 */
class EnsureLookoutAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Lookout::enabled(), 404);
        abort_unless(Lookout::allows($request->user()), 403);

        return $next($request);
    }
}

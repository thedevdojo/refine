<?php

namespace DevDojo\Refine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RefineMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Only allow Refine to work in local environments.
     */
    public function handle(Request $request, Closure $next)
    {
        // Block access if Refine is not enabled
        if (!config('refine.enabled')) {
            abort(403, 'Refine is only available in local development environments.');
        }

        // Additional security: check APP_ENV explicitly
        if (app()->environment() !== 'local') {
            abort(403, 'Refine is only available in local development environments.');
        }

        return $next($request);
    }
}

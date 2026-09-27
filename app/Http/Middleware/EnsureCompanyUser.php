<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure the user has completed org login context (org user, not platform-only).
 */
class EnsureCompanyUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 403);

        if ($user->is_platform_admin && $user->organization_id === null) {
            // Platform admins use platform routes; company area requires org context
            // unless they explicitly belong to an organization.
            abort(403, 'Platform administrators use the platform area.');
        }

        abort_unless($user->organization_id !== null, 403, 'No organization context.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API counterpart of SetOrganizationContext: loads roles/permissions for Gate
 * checks and blocks suspended organizations. No session handling — API state
 * is carried by bearer tokens.
 */
class EnsureApiOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Always refresh roles/permissions for Gate checks (relation may have
        // been cached empty before auth context existed).
        $user->load('roles.permissions');

        if ($user->organization_id !== null) {
            $organization = $user->organization;

            if ($organization !== null && ! $organization->isActive()) {
                abort(403, 'This organization is suspended.');
            }
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API counterpart of SetOrganizationContext: loads roles/permissions for Gate
 * checks and blocks suspended organizations. No session handling — API state
 * is carried by bearer tokens or API keys.
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
        // been cached empty before auth context existed). API keys have no
        // roles — their abilities are checked directly via canPermission().
        if ($user instanceof User) {
            $user->load('roles.permissions');
        }

        if ($user->organization_id !== null) {
            $organization = $user->organization;

            if ($organization !== null && ! $organization->isActive()) {
                abort(403, 'This organization is suspended.');
            }
        }

        return $next($request);
    }
}

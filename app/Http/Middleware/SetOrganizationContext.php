<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads organization context for the authenticated user and blocks suspended orgs.
 */
class SetOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->is_platform_admin) {
            $user->load('roles.permissions');

            return $next($request);
        }

        // Users without an org (e.g. mid-registration) pass through;
        // EnsureCompanyUser blocks company routes when org context is required.
        if ($user->organization_id !== null) {
            $organization = $user->organization;

            if ($organization !== null && ! $organization->isActive()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                abort(403, 'This organization is suspended.');
            }
        }

        // Always refresh roles/permissions for Gate checks (relation may have
        // been cached empty before auth context existed).
        $user->load('roles.permissions');

        return $next($request);
    }
}

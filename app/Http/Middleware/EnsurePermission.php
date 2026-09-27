<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny access unless the authenticated user has the given permission key
 * or is a platform admin.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 403);
        abort_unless($user->canPermission($permission), 403, 'Forbidden: missing permission '.$permission);

        return $next($request);
    }
}

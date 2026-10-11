<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\ScimToken;
use App\Models\User;
use App\Services\RoleSeederService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Phase 8 — API keys and SCIM tokens authenticate through dedicated
        // request guards so they can share middleware stacks with sanctum.
        Auth::viaRequest('apikey', fn ($request) => ApiKey::resolveFromRequest($request));
        Auth::viaRequest('scim', fn ($request) => ScimToken::resolveFromRequest($request));
        Gate::before(function ($user, string $ability): ?bool {
            if ($user instanceof User && $user->is_platform_admin) {
                return true;
            }

            return null;
        });

        // Map permission keys to gates dynamically. The subject can be a
        // User (role-derived permissions) or an ApiKey/ScimToken (ability
        // list) — both expose canPermission().
        foreach (array_keys(RoleSeederService::CATALOG) as $key) {
            Gate::define($key, fn ($user) => method_exists($user, 'canPermission') && $user->canPermission($key));
        }

        Gate::define('platform.organizations', fn ($user) => $user instanceof User && $user->is_platform_admin);

        // Password-recovery support tickets: org admins, the manager role,
        // and anyone who actually manages a department (not a catalog key —
        // visibility follows departments.manager_id, so @can/middleware both
        // resolve through this gate).
        Gate::define('support.view', fn ($user) => $user instanceof User && ($user->hasRole('company_admin')
            || $user->hasRole('manager')
            || $user->managedDepartments()->exists()));

        View::composer('*', function ($view) {
            $user = auth()->user();
            if ($user && ! $user->is_platform_admin && $user->organization_id) {
                $view->with('currentOrganization', $user->organization);
            }
        });
    }
}

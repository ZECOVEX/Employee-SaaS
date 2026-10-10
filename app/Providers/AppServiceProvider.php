<?php

namespace App\Providers;

use App\Models\User;
use App\Services\RoleSeederService;
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
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->is_platform_admin) {
                return true;
            }

            return null;
        });

        // Map permission keys to gates dynamically
        foreach (array_keys(RoleSeederService::CATALOG) as $key) {
            Gate::define($key, fn (User $user) => $user->canPermission($key));
        }

        Gate::define('platform.organizations', fn (User $user) => $user->is_platform_admin);

        // Password-recovery support tickets: org admins, the manager role,
        // and anyone who actually manages a department (not a catalog key —
        // visibility follows departments.manager_id, so @can/middleware both
        // resolve through this gate).
        Gate::define('support.view', fn (User $user) => $user->hasRole('company_admin')
            || $user->hasRole('manager')
            || $user->managedDepartments()->exists());

        View::composer('*', function ($view) {
            $user = auth()->user();
            if ($user && ! $user->is_platform_admin && $user->organization_id) {
                $view->with('currentOrganization', $user->organization);
            }
        });
    }
}

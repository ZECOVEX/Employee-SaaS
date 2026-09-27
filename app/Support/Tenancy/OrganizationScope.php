<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope that restricts tenant-owned rows to the current user's organization.
 * Platform admins bypass the scope only when explicitly enabled via withoutGlobalScope.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user === null) {
            // Unauthenticated context (e.g. CLI) sees nothing tenant-owned by default.
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($user->is_platform_admin) {
            return;
        }

        if ($user->organization_id === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), $user->organization_id);
    }
}

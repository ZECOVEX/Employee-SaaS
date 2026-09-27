<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Apply to models that belong to an organization.
 * Adds global OrganizationScope and auto-fills organization_id on create.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            if ($model instanceof OrganizationScoped && $model->isPlatformOwned()) {
                return;
            }

            if ($model->getAttribute('organization_id') === null) {
                $user = Auth::user();
                if ($user?->organization_id) {
                    $model->setAttribute('organization_id', $user->organization_id);
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Bypass org scope for platform-level queries (super admin tooling).
     */
    public static function forPlatform(): Builder
    {
        return static::query()->withoutGlobalScope(OrganizationScope::class);
    }
}

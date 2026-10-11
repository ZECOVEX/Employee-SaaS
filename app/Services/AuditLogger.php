<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function log(
        string $action,
        ?Model $resource = null,
        ?array $old = null,
        ?array $new = null,
    ): AuditLog {
        $user = Auth::user();

        // Resource organization wins so a platform admin's action on another
        // tenant lands in that tenant's trail; Organization resources (status
        // toggles, registration) fall back to their own key, then the actor.
        $organizationId = $resource?->getAttribute('organization_id')
            ?? ($resource instanceof Organization ? $resource->getKey() : null)
            ?? $user?->organization_id;

        return AuditLog::create([
            // Console/scheduled runs have no auth — fall back to the resource's
            // own organization so tenancy never depends on a signed-in user.
            'organization_id' => $organizationId,
            'actor_user_id' => $user?->id,
            'action' => $action,
            'resource_type' => $resource ? class_basename($resource) : null,
            'resource_id' => $resource?->getKey(),
            'old_value' => $old,
            'new_value' => $new,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function sensitive(Model $model, array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key) {
            if ($model->isFillable($key) || array_key_exists($key, $model->getAttributes())) {
                $out[$key] = $model->getAttribute($key);
            }
        }

        return $out;
    }
}

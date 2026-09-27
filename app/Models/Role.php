<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['organization_id', 'name', 'slug'])]
class Role extends Model
{
    use BelongsToOrganization;

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role');
    }

    public function givePermission(string|int $permission): void
    {
        $id = is_numeric($permission)
            ? (int) $permission
            : Permission::where('key', $permission)->value('id');

        if ($id) {
            $this->permissions()->syncWithoutDetaching([$id]);
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function syncPermissionKeys(array $keys): void
    {
        $ids = Permission::whereIn('key', $keys)->pluck('id')->all();
        $this->permissions()->sync($ids);
    }
}

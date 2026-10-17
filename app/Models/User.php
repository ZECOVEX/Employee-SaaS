<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RoleSeederService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'organization_id',
    'name',
    'email',
    'avatar_path',
    'phone',
    'password',
    'must_change_password',
    'is_platform_admin',
    'last_login_at',
    'last_login_ip',
    'last_login_geo_division',
    'last_login_geo_area',
    'deactivated_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // Intentionally NO global organization scope on User:
    // login/password-reset resolve users before a session user exists.
    // Tenant isolation is enforced in controllers and middleware via organization_id.

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_platform_admin' => 'boolean',
            'last_login_at' => 'datetime',
            // Phase 8 — TOTP MFA: secret encrypted at rest, recovery codes a
            // json list of sha256 hashes. Post-Phase 21 — mfa_method picks
            // authenticator app ('totp') vs email codes ('email').
            'mfa_secret' => 'encrypted',
            'mfa_enabled' => 'boolean',
            'mfa_recovery_codes' => 'array',
            'login_alerts_enabled' => 'boolean',
            // §43 — admin-controlled deactivation blocks login until cleared.
            'deactivated_at' => 'datetime',
        ];
    }

    public function loginHistory(): HasMany
    {
        return $this->hasMany(UserLoginHistory::class)->latest('id')->limit(20);
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    /**
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function primaryRoleSlug(): ?string
    {
        return $this->roles->first()?->slug;
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    public function hasAnyRole(array $slugs): bool
    {
        return $this->roles->whereIn('slug', $slugs)->isNotEmpty();
    }

    /**
     * @return array<string>
     */
    public function permissionKeys(): array
    {
        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('key'))
            ->unique()
            ->values()
            ->all();
    }

    public function canPermission(string $key): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        return in_array($key, $this->permissionKeys(), true);
    }

    /**
     * Permission keys this user may grant to roles or API keys: everything
     * they hold (platform admins hold the whole catalog) plus the self-scope
     * keys that sit outside the company_admin role (own-team / own-data), so
     * any delegated editor may still assign employee-scoped access. A
     * delegated role or key editor can never grant what they do not hold —
     * re-delegation cannot widen their own access.
     *
     * @return array<int, string>
     */
    public function grantablePermissionKeys(): array
    {
        $all = array_keys(RoleSeederService::CATALOG);

        if ($this->is_platform_admin) {
            return $all;
        }

        $held = array_intersect($all, $this->permissionKeys());
        $selfScope = array_diff($all, RoleSeederService::ROLE_PERMISSIONS['company_admin']);

        return array_values(array_unique([...$held, ...$selfScope]));
    }

    /**
     * Plain employee accounts (§3): identity is HR-managed and they may not
     * delete their own account.
     */
    public function isEmployeeOnly(): bool
    {
        return $this->hasRole('employee')
            && ! $this->hasAnyRole(['company_admin', 'hr', 'manager'])
            && ! $this->is_platform_admin;
    }

    public function managedDepartments(): HasMany
    {
        return $this->hasMany(Department::class, 'manager_id');
    }
}

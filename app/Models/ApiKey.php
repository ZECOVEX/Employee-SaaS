<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Phase 8 — org-scoped API key for /api/v1. Registered with the `apikey`
 * request guard and behaves as a minimal Authenticatable: permissions come
 * from the stored `abilities` list (or `*` for full org access), never roles.
 */
class ApiKey extends Authenticatable
{
    protected $table = 'api_keys';

    /** Full-access keys act as org admins for data-scoping checks. */
    public bool $is_platform_admin = false;

    protected $fillable = [
        'organization_id',
        'name',
        'prefix',
        'key_hash',
        'abilities',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Own-data API endpoints (`/mine`) resolve the employee the key was
     * created for's organization employee set; keys have no employee.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function canPermission(string $key): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        $abilities = $this->abilities ?? ['*'];

        return in_array('*', $abilities, true) || in_array($key, $abilities, true);
    }

    public function hasAnyRole(array $slugs): bool
    {
        return in_array('*', $this->abilities ?? [], true);
    }

    public function hasRole(string $slug): bool
    {
        return $this->hasAnyRole([$slug]);
    }

    /**
     * Issue a new key; the plaintext secret is returned once and never stored.
     */
    public static function issue(Organization $organization, string $name, ?array $abilities, Authenticatable $actor): string
    {
        $secret = 'emsak_'.Str::random(40);

        self::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'prefix' => substr($secret, 0, 12),
            'key_hash' => hash('sha256', $secret),
            'abilities' => $abilities === null ? null : array_values($abilities),
            'created_by' => $actor->id,
        ]);

        return $secret;
    }

    /**
     * Resolve a raw key from the request (X-API-Key header or bearer token).
     */
    public static function resolveFromRequest(Request $request): ?self
    {
        $raw = $request->header('X-API-Key') ?: $request->bearerToken();

        if ($raw === null || ! str_starts_with($raw, 'emsak_')) {
            return null;
        }

        $key = self::query()
            ->where('prefix', substr($raw, 0, 12))
            ->whereNull('revoked_at')
            ->first();

        if ($key === null || ! hash_equals($key->key_hash, hash('sha256', $raw))) {
            return null;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $key;
    }

    public static function current(): ?self
    {
        $user = Auth::user();

        return $user instanceof self ? $user : null;
    }
}

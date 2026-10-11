<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Phase 8 — SCIM 2.0 bearer token for /api/scim/v2 provisioning. Registered
 * with the `scim` request guard; grants full provisioning abilities inside
 * its own organization (per-endpoint permission keys are still checked).
 */
class ScimToken extends Authenticatable
{
    protected $table = 'scim_tokens';

    /** SCIM tokens never act as platform admins. */
    public bool $is_platform_admin = false;

    protected $fillable = [
        'organization_id',
        'name',
        'token_hash',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
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
     * SCIM tokens are provisioners: allow any catalog permission inside the
     * tenant (controllers still scope every query by organization_id).
     */
    public function canPermission(string $key): bool
    {
        return $this->revoked_at === null;
    }

    public function hasAnyRole(array $slugs): bool
    {
        return $this->revoked_at === null;
    }

    public function hasRole(string $slug): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Issue a new token; the plaintext is returned once and never stored.
     */
    public static function issue(Organization $organization, string $name, Authenticatable $actor): string
    {
        $token = 'scim_'.Str::random(40);

        self::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'created_by' => $actor->id,
        ]);

        return $token;
    }

    /**
     * Resolve a raw bearer token from the request.
     */
    public static function resolveFromRequest(Request $request): ?self
    {
        $raw = $request->bearerToken();

        if ($raw === null || ! str_starts_with($raw, 'scim_')) {
            return null;
        }

        $token = self::query()
            ->where('token_hash', hash('sha256', $raw))
            ->whereNull('revoked_at')
            ->first();

        if ($token === null) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $token;
    }

    public static function current(): ?self
    {
        $user = Auth::user();

        return $user instanceof self ? $user : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §71 — one row per login attempt. organization_id is nullable so failed
 * attempts for unknown emails (no org context) are still recorded for
 * security monitoring; tenant scoping applies to every org-owned row.
 */
class UserLoginHistory extends Model
{
    use BelongsToOrganization;

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_MFA_FAILED = 'MFA_FAILED';

    protected $table = 'user_login_history';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'ip_address',
        'geo_country',
        'geo_division',
        'geo_area',
        'user_agent',
        'login_status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Human-readable location label: "Dhaka, Bangladesh" / "Bangladesh" / "—". */
    public function locationLabel(): string
    {
        $parts = array_filter([
            $this->geo_division,
            $this->geo_country,
        ], fn ($part) => $part !== null && $part !== '');

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /** Coarse "Chrome on Windows" label parsed from the stored user agent. */
    public function deviceLabel(): string
    {
        $ua = (string) $this->user_agent;

        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Unknown browser',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return $browser.' on '.$os;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\Models\User;
use App\Models\UserLoginHistory;
use Illuminate\Support\Facades\Log;

/**
 * §71 — records every login attempt (success or failure) with the resolved
 * coarse location and maintains the denormalized "latest login" snapshot on
 * the users table for fast profile display.
 */
class LoginHistoryService
{
    public function __construct(private readonly GeoLocator $locator) {}

    public function record(?User $user, string $status, ?string $ip = null, ?string $userAgent = null): UserLoginHistory
    {
        $geo = $this->locator->locate($ip);

        $entry = UserLoginHistory::query()->create([
            'organization_id' => $user?->organization_id,
            'user_id' => $user?->id,
            'ip_address' => $ip,
            'geo_country' => $geo['country'],
            'geo_division' => $geo['division'],
            'geo_area' => $geo['area'],
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'login_status' => $status,
        ]);

        if ($user !== null && $status === UserLoginHistory::STATUS_SUCCESS) {
            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $ip,
                'last_login_geo_division' => $geo['division'],
                'last_login_geo_area' => $geo['area'],
            ])->save();
        }

        Log::info('auth.login_history', [
            'user_id' => $user?->id,
            'organization_id' => $user?->organization_id,
            'status' => $status,
            'ip' => $ip,
            'geo' => $geo,
        ]);

        return $entry;
    }

    /** Convenience: record from the current request. */
    public function recordFromRequest(?User $user, string $status): UserLoginHistory
    {
        return $this->record(
            $user,
            $status,
            request()?->ip(),
            request()?->userAgent(),
        );
    }
}

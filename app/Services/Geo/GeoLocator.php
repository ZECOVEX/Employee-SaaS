<?php

declare(strict_types=1);

namespace App\Services\Geo;

use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\Cache;

/**
 * §71 — server-side, best-effort IP → coarse location resolution. Never
 * resolves on the client. Drivers:
 *
 *  - `null`     → all fields null (default; no database or provider configured)
 *  - `static`   → longest-prefix match against config/geoip.php ranges
 *                 (offline / dev / tests; ship CIDR blocks for your regions)
 *  - `maxmind`  → GeoLite2/GeoIP2 mmdb via geoip2/geoip2 when installed and
 *                 the database file exists (production path)
 *
 * Results are cached per IP so repeated lookups never hit the provider.
 * Output is approximate and must only ever be used as a signal alongside
 * rate limiting and MFA — never as the sole basis for blocking access.
 */
class GeoLocator
{
    /**
     * @return array{country: ?string, division: ?string, area: ?string}
     */
    public function locate(?string $ip): array
    {
        $empty = ['country' => null, 'division' => null, 'area' => null];

        if ($ip === null || $ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $empty;
        }

        // Loopback / private ranges resolve to nothing useful.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $empty;
        }

        $driver = (string) config('geoip.driver', 'null');

        if ($driver === 'null') {
            return $empty;
        }

        return Cache::remember('geoip:'.md5($ip), 3600, function () use ($driver, $ip, $empty) {
            $result = match ($driver) {
                'static' => $this->locateStatic($ip),
                'maxmind' => $this->locateMaxMind($ip),
                default => $empty,
            };

            return array_merge($empty, $result);
        });
    }

    /** @return array{country?: string, division?: string, area?: string} */
    protected function locateStatic(string $ip): array
    {
        $ranges = (array) config('geoip.ranges', []);
        $best = [];
        $bestBits = -1;

        foreach ($ranges as $range) {
            $cidr = (string) ($range['cidr'] ?? '');
            $bits = $this->cidrMatch($ip, $cidr);

            if ($bits !== false && $bits > $bestBits) {
                $bestBits = $bits;
                $best = $range;
            }
        }

        if ($best === []) {
            return [];
        }

        return array_filter([
            'country' => $best['country'] ?? null,
            'division' => $best['division'] ?? null,
            'area' => $best['area'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** @return array{country?: string, division?: string, area?: string} */
    protected function locateMaxMind(string $ip): array
    {
        $path = (string) config('geoip.maxmind_database', '');

        if ($path === '' || ! is_file($path) || ! class_exists(Reader::class)) {
            return [];
        }

        try {
            $reader = new Reader($path);
            $record = $reader->country($ip);

            $result = ['country' => $record->country->name ?? null];

            if (method_exists($record, 'subdivisions') && isset($record->subdivisions->name)) {
                $result['division'] = $record->subdivisions->name;
            }

            if (isset($record->city->name)) {
                $result['area'] = $record->city->name;
            }

            $reader->close();

            return array_filter($result, fn ($value) => $value !== null);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Longest-prefix bits when $ip falls inside $cidr, else false. */
    protected function cidrMatch(string $ip, string $cidr): int|false
    {
        if ($cidr === '' || ! str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask) ? $bits : false;
    }

    /**
     * §32 — is the IP covered by an allowlist of exact addresses or IPv4
     * CIDR blocks? Used for terminal IP restrictions.
     *
     * @param  list<string>  $allowlist
     */
    public static function matchesList(string $ip, array $allowlist): bool
    {
        foreach ($allowlist as $entry) {
            $entry = trim((string) $entry);

            if ($entry === '') {
                continue;
            }

            if ($entry === $ip) {
                return true;
            }

            if (str_contains($entry, '/')) {
                $locator = new self;

                if ($locator->cidrMatch($ip, $entry) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}

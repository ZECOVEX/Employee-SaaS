<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | GeoIP driver (§71)
    |--------------------------------------------------------------------------
    | 'null'    — no resolution (all fields null); the safe default.
    | 'static'  — longest-prefix match against the ranges below (offline).
    | 'maxmind' — GeoLite2/GeoIP2 mmdb via geoip2/geoip2 (install the package
    |             and set GEOIP_MAXMIND_DATABASE to the .mmdb file path).
    |
    | Resolution is best-effort and approximate — use only as a security
    | signal alongside rate limiting and MFA, never as the sole blocker.
    */

    'driver' => env('GEOIP_DRIVER', 'null'),

    'maxmind_database' => env('GEOIP_MAXMIND_DATABASE', ''),

    // Example offline ranges for Bangladesh (division-level, best-effort).
    // Replace/extend with your provider's blocks for production accuracy.
    'ranges' => [
        ['cidr' => '103.108.0.0/14', 'country' => 'Bangladesh', 'division' => 'Dhaka', 'area' => null],
        ['cidr' => '103.72.0.0/14', 'country' => 'Bangladesh', 'division' => 'Chattogram', 'area' => null],
        ['cidr' => '114.130.0.0/16', 'country' => 'Bangladesh', 'division' => 'Dhaka', 'area' => 'Dhaka'],
        ['cidr' => '27.147.128.0/18', 'country' => 'Bangladesh', 'division' => 'Khulna', 'area' => null],
        ['cidr' => '103.4.64.0/18', 'country' => 'Bangladesh', 'division' => 'Rajshahi', 'area' => null],
        ['cidr' => '45.113.192.0/18', 'country' => 'Bangladesh', 'division' => 'Sylhet', 'area' => null],
        ['cidr' => '119.40.96.0/19', 'country' => 'Bangladesh', 'division' => 'Barishal', 'area' => null],
        ['cidr' => '103.28.128.0/19', 'country' => 'Bangladesh', 'division' => 'Rangpur', 'area' => null],
        ['cidr' => '114.44.0.0/16', 'country' => 'Bangladesh', 'division' => 'Mymensingh', 'area' => null],
    ],

];

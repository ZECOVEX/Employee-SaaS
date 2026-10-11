<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * §44 — liveness and readiness probes. Both are public (no auth) and return
 * only booleans/latency — never tenant data. /health answers "is the app
 * process alive"; /ready additionally checks the database and cache store so
 * a load balancer can hold traffic back during deploys/migrations.
 */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'time' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    /** @return array{ok: bool, latency_ms: float} */
    private function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            DB::select('select 1');
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'latency_ms' => round((microtime(true) - $start) * 1000, 2)];
    }

    /** @return array{ok: bool, latency_ms: float} */
    private function checkCache(): array
    {
        $start = microtime(true);

        try {
            $key = 'health:ready:'.getmypid();
            cache()->put($key, 1, 10);
            $ok = cache()->get($key) === 1;
            cache()->forget($key);
        } catch (\Throwable) {
            $ok = false;
        }

        return ['ok' => $ok, 'latency_ms' => round((microtime(true) - $start) * 1000, 2)];
    }
}

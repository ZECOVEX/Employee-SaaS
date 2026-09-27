<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceTerminal;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class PunchController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_token' => ['required', 'string', 'max:64'],
        ]);

        $key = $request->header('X-Terminal-Key') ?? $request->input('terminal_key');

        if (! $key) {
            return response()->json(['message' => 'Missing terminal key.'], 401);
        }

        $terminal = AttendanceTerminal::withoutGlobalScopes()
            ->where('key_hash', hash('sha256', $key))
            ->where('status', 'active')
            ->first();

        if (! $terminal) {
            return response()->json(['message' => 'Invalid or revoked terminal key.'], 401);
        }

        $limiterKey = 'punch:'.$terminal->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 30)) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }
        RateLimiter::hit($limiterKey, 60);

        if ($terminal->organization?->status !== 'active') {
            return response()->json(['message' => 'Organization is suspended.'], 403);
        }

        try {
            $result = $this->attendance->punch($terminal, $data['card_token']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $employee = $result['event']->employee;
        $name = $employee?->user?->name;
        $daily = $result['daily'];

        $message = match ($result['action']) {
            'check_in' => 'Check-in recorded.',
            'check_out' => 'Check-out recorded.',
            default => 'Duplicate punch ignored.',
        };

        return response()->json([
            'message' => $message,
            'action' => $result['action'],
            'employee' => [
                'code' => $employee?->employee_code,
                'name' => $name,
            ],
            'event_type' => $result['event']->event_type,
            'occurred_at' => $result['event']->occurred_at->toIso8601String(),
            'daily' => [
                'status' => $daily->status,
                'first_check_in' => $daily->first_check_in?->toIso8601String(),
                'last_check_out' => $daily->last_check_out?->toIso8601String(),
                'total_work_minutes' => $daily->total_work_minutes,
            ],
        ]);
    }
}

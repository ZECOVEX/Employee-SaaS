<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\LiveAttendanceBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Live attendance board (§19) with a Server-Sent Events stream and a
 * lightweight JSON polling fallback for hosts where SSE is unavailable.
 */
class LiveAttendanceController extends Controller
{
    public function __construct(private readonly LiveAttendanceBoard $board) {}

    public function index(Request $request): View
    {
        Gate::authorize('attendance.view');

        $organization = $this->organization($request);
        $rows = $this->board->rows($organization);

        return view('attendance.live', [
            'rows' => $rows,
            'hash' => $this->hash($rows),
            'streamUrl' => route('attendance.live.stream'),
            'pollUrl' => route('attendance.live.poll'),
            'pollInterval' => max(2, (int) config('live.poll_interval_seconds', 5)),
            'updatedAt' => now($organization->timezone)->toDateTimeString(),
        ]);
    }

    public function stream(Request $request): StreamedResponse
    {
        Gate::authorize('attendance.view');

        $organization = $this->organization($request);
        $seconds = max(0, (int) config('live.stream_seconds', 30));

        return response()->stream(function () use ($organization, $seconds) {
            echo "retry: 3000\n\n";

            $start = microtime(true);

            while (true) {
                echo 'data: '.json_encode($this->payload($organization))."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

                if (microtime(true) - $start >= $seconds) {
                    break;
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        Gate::authorize('attendance.view');

        $organization = $this->organization($request);
        $payload = $this->payload($organization);
        $after = (string) $request->query('after', '');

        // Same hash as before → the board has not changed; skip the rows.
        if ($after !== '' && hash_equals($payload['hash'], $after)) {
            return response()->json([
                'changed' => false,
                'hash' => $payload['hash'],
                'at' => $payload['at'],
            ]);
        }

        return response()->json($payload + ['changed' => true]);
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()->organization;
        abort_unless($organization, 403);

        return $organization;
    }

    /**
     * @return array{at: string, hash: string, rows: array<int, array<string, mixed>>}
     */
    private function payload(Organization $organization): array
    {
        $rows = $this->board->rows($organization);

        return [
            'at' => now($organization->timezone)->toDateTimeString(),
            'hash' => $this->hash($rows),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function hash(array $rows): string
    {
        return md5((string) json_encode($rows));
    }
}

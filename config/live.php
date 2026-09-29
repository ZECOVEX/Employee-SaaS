<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live attendance board (§19)
    |--------------------------------------------------------------------------
    |
    | The SSE stream re-emits a board snapshot every second for this many
    | seconds, then closes; the browser's EventSource reconnects on its own.
    | Tests set it to 0 so the stream emits one snapshot and returns.
    |
    */

    'stream_seconds' => (int) env('LIVE_STREAM_SECONDS', 30),

    // Polling fallback interval (§ hosting fallback) when SSE is unavailable.
    'poll_interval_seconds' => (int) env('LIVE_POLL_INTERVAL', 5),

];

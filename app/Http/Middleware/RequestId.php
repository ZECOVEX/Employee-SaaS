<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * §44/§62 — every request carries an X-Request-Id (generated when the client
 * did not send one) and that id plus the acting user/org are shared with the
 * log context so application, error and audit-adjacent logs correlate.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) $request->header('X-Request-Id', '');

        if ($requestId === '' || strlen($requestId) > 64) {
            $requestId = (string) Str::uuid();
        }

        $request->headers->set('X-Request-Id', $requestId);

        Log::shareContext([
            'request_id' => $requestId,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'organization_id' => $request->user()?->organization_id,
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}

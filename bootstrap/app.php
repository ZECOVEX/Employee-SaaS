<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureApiOrganization;
use App\Http\Middleware\EnsureCompanyUser;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetOrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // §44 observability — public liveness/readiness probes (no auth).
            Route::get('/health', [HealthController::class, 'health'])->name('health');
            Route::get('/ready', [HealthController::class, 'ready'])->name('ready');
        },
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization' => SetOrganizationContext::class,
            'company' => EnsureCompanyUser::class,
            'permission' => EnsurePermission::class,
            'platform_admin' => EnsurePlatformAdmin::class,
            'api_organization' => EnsureApiOrganization::class,
        ]);

        // §62 security headers + §44 request-id correlation on every request.
        $middleware->prepend([
            RequestId::class,
            SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            SetOrganizationContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // §62 centralized error handling — a stable JSON envelope for the
        // common HTTP failures on the API surface (message + optional field
        // errors), matching what the web forms already expect.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => $e->getMessage() ?: 'This action is unauthorized.'], 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Resource not found.'], 404);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Too many requests.'], 429);
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => $e->getMessage() ?: 'Server error.'], (int) $e->getStatusCode());
        });
    })->create();

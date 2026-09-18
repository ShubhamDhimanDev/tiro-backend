<?php

use App\Exceptions\Api\TooManyRequestsException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Custom escalating lockouts (password login, §7) — always a
        // {message, retry_after} JSON body per the customer-auth contract.
        $exceptions->renderable(fn (TooManyRequestsException $e) => response()->json([
            'message' => $e->getMessage(),
            'retry_after' => $e->retryAfter,
        ], 429, ['Retry-After' => (string) $e->retryAfter]));

        // Stock `throttle:<limiter>` middleware failures (OTP request/verify
        // families, §6) — same {message, retry_after} shape for API routes.
        $exceptions->renderable(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Too many attempts. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429, $e->getHeaders());
        });
    })->create();

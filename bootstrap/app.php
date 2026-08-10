<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\UnauthorizedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'service.token' => \App\Http\Middleware\ValidateServiceToken::class,
            'admissions.active' => \App\Http\Middleware\EnsureAdmissionsAreActive::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*Uncomment this to render JSON responses for API errors
        $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
         */

        $exceptions->render(function (
            InvalidSignatureException $e,
            Request $request
        ) {
            return response()->json([
                'message' => 'Invalid signature',
                'error'   => 'INVALID_SIGNATURE',
            ], 403);
        });

        $exceptions->render(function (
            UnauthorizedException $e,
            Request $request
        ) {
            return response()->json([
                'message' => __('permissions.unauthorized'),
                'error'   => 'FORBIDDEN',
            ], 403);
        });

        /**
         * Never leak SQL / schema details to API clients.
         * ValidationException keeps Laravel's default 422 + errors bag.
         * Uncomment this to render JSON responses for database errors**/
        /*
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            Log::error('Database query failed', [
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'url' => $request->fullUrl(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar los datos. Inténtalo de nuevo.',
                'error' => 'DATABASE_ERROR',
            ], 500);
        });
        */
    })->create();

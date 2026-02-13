<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;
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
    })->create();

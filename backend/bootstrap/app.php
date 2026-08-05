<?php

use App\Http\Middleware\EnsureMoneyPinVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Spatie role/permission gates — used to protect every OperON screen.
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

            // Extra PIN confirmation for cost/price/margin screens (blueprint §S).
            'money.pin' => EnsureMoneyPinVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Answer in the format the caller asked for.
         *
         * `api/*` was the original rule. `expectsJson()` was added at M6 for the master
         * grid, which saves a cell with fetch() and needs the reason a save was refused -
         * a 302 to an HTML page tells it nothing, and it would have to guess. Only
         * requests that explicitly send Accept: application/json are affected, so no
         * browser form flow changes.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

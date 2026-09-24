<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CheckRoleHierarchy;
use App\Http\Middleware\EnsureBaseRole;
use App\Http\Middleware\EnsureAutonomoRole;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware) {
        // CORS debe estar primero, antes de cualquier otro middleware
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'role.hierarchy' => CheckRoleHierarchy::class,
            'base.role'      => EnsureBaseRole::class,
            'autonomo.role'  => EnsureAutonomoRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

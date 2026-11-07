<?php

use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireStudentLock;
use App\Http\Middleware\RequireUserAccess;
use App\Http\Middleware\StaffSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => RequireAdmin::class, 'staff-session' => StaffSession::class,
            'student-lock' => RequireStudentLock::class, 'user-access' => RequireUserAccess::class]);
        // Check the user's level before loading route models, so unauthorized users get 403 rather than 404.
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireUserAccess::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireAdmin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

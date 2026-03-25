<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Auto-delete old activity logs daily at midnight
        $schedule->command('activity-log:clean')->daily();

        // Sync employees from Semesta API every 6 hours
        $schedule->command('employees:sync')->everySixHours();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // CORS for Next.js server-side proxy
        $middleware->append(\App\Http\Middleware\CorsMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

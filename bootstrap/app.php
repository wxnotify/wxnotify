<?php

use App\Http\Middleware\VerifyPipelineWebhookToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/public.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'pipeline.webhook' => VerifyPipelineWebhookToken::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('shapefile:scan')->daily();
        $schedule->command('alerts:dispatch-scheduled')->everyFifteenMinutes()->withoutOverlapping(30);
        $schedule->command('traccar:poll-positions')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('pipeline:prune-deliveries')->hourly();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

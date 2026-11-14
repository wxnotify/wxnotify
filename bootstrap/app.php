<?php

use App\Http\Middleware\VerifyPipelineWebhookToken;
use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

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
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'pipeline.webhook' => VerifyPipelineWebhookToken::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : route('filament.dashboard.auth.login'));
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('shapefile:scan')->daily();
        $schedule->command('alerts:dispatch-scheduled')->everyFifteenMinutes()->withoutOverlapping(30);
        $schedule->command('alerts:dispatch-outlook-reports')->everyFifteenMinutes()->withoutOverlapping(30);
        $schedule->command('traccar:poll-positions')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('pipeline:prune-deliveries')->hourly();
        $schedule->command('accounts:purge-deleted')->daily()->withoutOverlapping();
        $schedule->command('storage:scan-products')->hourly()->withoutOverlapping(60);
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        $schedule->command('outlook:prune-maps')->daily();
        $schedule->command('queue:prune-failed --hours=168')->daily();
        $schedule->job(new RecordQueueHeartbeat)->everyMinute();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

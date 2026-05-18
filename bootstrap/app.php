<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \App\Http\Middleware\AssignRequestId::class,
        ]);
        $middleware->statefulApi();
        
        // Deshabilitar CSRF para rutas API y web root
        $middleware->validateCsrfTokens(except: [
            'api/*',
            '/',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Reportar excepciones a Sentry (solo si DSN está configurado)
        $exceptions->report(function (\Throwable $e) {
            if (app()->bound('sentry') && config('sentry.dsn')) {
                \Sentry\captureException($e);
            }
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.'
                ], 401);
            }
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        /** @see App\Console\Kernel (legacy: el schedule activo es este bloque en Laravel 11) */
        $schedule->command('jobs:check-inactive')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('analytics:prune')->dailyAt('03:15');

        $schedule->command('analytics:anonymize-pii')->weeklyOn(0, '04:00');

        $schedule->command('analytics:report-cohort-slack')->weeklyOn(1, '9:00');

        $schedule->command('retention:push-open-requests')
            ->weeklyOn(1, '10:00')
            ->withoutOverlapping();

        $schedule->command('retention:push-worker-availability')
            ->weeklyOn(3, '10:30')
            ->withoutOverlapping();

        $schedule->command('jobshour:expire-pending-accept')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('jobshour:sla-accept-reminders')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Snapshot de métricas para el dashboard de Horizon
        $schedule->command('horizon:snapshot')->everyFiveMinutes();

        // Alerta Slack si Postgres no responde (sencillo: si health devuelve error, log CRITICAL)
        $schedule->call(function () {
            try {
                \Illuminate\Support\Facades\DB::select('SELECT 1');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::critical('[DB] PostgreSQL unreachable — check connection', [
                    'error' => $e->getMessage(),
                ]);
            }
        })->everyFiveMinutes()->name('db-health-check')->withoutOverlapping();
    })
    ->create();

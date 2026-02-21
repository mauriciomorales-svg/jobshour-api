<?php

namespace App\Providers;

use App\Events\ServiceRequestCreated;
use App\Events\ServiceRequestUpdated;
use App\Events\NewMessage;
use App\Listeners\SendPushNotifications;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Búsqueda de workers en mapa — 120 req/min por IP
        RateLimiter::for('nearby', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Cambio de estado worker — 20 req/min por usuario
        RateLimiter::for('worker-status', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?? $request->ip());
        });

        // Autenticación — 10 intentos/min por IP
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Solicitudes de servicio — 15 req/min por usuario
        RateLimiter::for('requests', function (Request $request) {
            return Limit::perMinute(15)->by($request->user()?->id ?? $request->ip());
        });

        // Chat — 60 mensajes/min por usuario
        RateLimiter::for('chat', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?? $request->ip());
        });

        // Publicar demanda — 5 req/min por usuario (evitar spam)
        RateLimiter::for('demand', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?? $request->ip());
        });

        // FCM Push Notifications
        Event::listen(ServiceRequestCreated::class, [SendPushNotifications::class, 'handleDemandCreated']);
        Event::listen(ServiceRequestUpdated::class, [SendPushNotifications::class, 'handleDemandUpdated']);
        Event::listen(NewMessage::class, [SendPushNotifications::class, 'handleNewMessage']);
    }
}

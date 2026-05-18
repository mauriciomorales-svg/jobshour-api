<?php

namespace App\Services;

use App\Events\ServiceRequestCreated;
use App\Events\ServiceRequestUpdated;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Log;

/**
 * Punto único para broadcast WebSocket + FCM (vía listeners).
 * Evita duplicar broadcast + $event->handle() en controladores.
 */
class ServiceRequestNotificationDispatcher
{
    public static function published(ServiceRequest $serviceRequest): void
    {
        self::dispatchCreated($serviceRequest);
    }

    /** Demanda asignada a un worker (take, booking directo). */
    public static function assigned(ServiceRequest $serviceRequest): void
    {
        self::dispatchCreated($serviceRequest);
    }

    public static function updated(ServiceRequest $serviceRequest): void
    {
        $serviceRequest->loadMissing(['client', 'worker.user', 'category']);

        $event = new ServiceRequestUpdated($serviceRequest);

        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('[Notify] broadcast ServiceRequestUpdated failed', [
                'request_id' => $serviceRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        event($event);
    }

    public static function priceAdjustmentPending(ServiceRequest $serviceRequest): void
    {
        app(SendPushNotifications::class)->notifyPriceAdjustmentPending(
            $serviceRequest->loadMissing(['client', 'worker.user', 'category'])
        );
    }

    public static function priceAdjustmentApproved(ServiceRequest $serviceRequest): void
    {
        app(SendPushNotifications::class)->notifyPriceAdjustmentApproved(
            $serviceRequest->loadMissing(['client', 'worker.user', 'category'])
        );
    }

    private static function dispatchCreated(ServiceRequest $serviceRequest): void
    {
        $serviceRequest->loadMissing(['client', 'category', 'worker.user']);

        $event = new ServiceRequestCreated($serviceRequest);

        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('[Notify] broadcast ServiceRequestCreated failed', [
                'request_id' => $serviceRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        event($event);
    }
}

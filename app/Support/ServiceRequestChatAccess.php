<?php

namespace App\Support;

use App\Models\ServiceRequest;

/**
 * Reglas de acceso al chat por solicitud (evita hilos en pin padre ya tomado).
 */
class ServiceRequestChatAccess
{
    /** Pin público tomado: el chat vive en la solicitud derivada (worker_id asignado). */
    public static function isTakenPublicDemandParent(ServiceRequest $serviceRequest): bool
    {
        return $serviceRequest->status === 'pending'
            && $serviceRequest->worker_id === null
            && $serviceRequest->taken_by_worker_id !== null;
    }

    public static function canAccess(ServiceRequest $serviceRequest, int $userId): bool
    {
        if (self::isTakenPublicDemandParent($serviceRequest)) {
            return false;
        }

        if ((int) $serviceRequest->client_id === $userId) {
            return true;
        }

        if ($serviceRequest->worker && (int) $serviceRequest->worker->user_id === $userId) {
            return true;
        }

        return $serviceRequest->status === 'pending';
    }

    /** Estados en los que se pueden enviar mensajes. */
    public static function allowsSending(ServiceRequest $serviceRequest): bool
    {
        return in_array($serviceRequest->status, ['pending', 'accepted', 'in_progress', 'completed'], true);
    }
}

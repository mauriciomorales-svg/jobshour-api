<?php

use Illuminate\Support\Facades\Broadcast;

// Canal público de presencia de zona (todos pueden escuchar)
Broadcast::channel('presence-zone', function () {
    return true;
});

// Canal privado por worker (notificaciones personales)
Broadcast::channel('worker.{userId}', function ($user, $userId) {
    return $user->id === (int) $userId;
});

// Canal privado por usuario (notificaciones de cliente)
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return $user->id === (int) $userId;
});

// Canal de chat por solicitud
Broadcast::channel('chat.{serviceRequestId}', function ($user, $serviceRequestId) {
    $sr = \App\Models\ServiceRequest::find($serviceRequestId);
    if (!$sr) return false;
    // Cliente o worker asignado siempre pueden
    if ($user->id === $sr->client_id) return true;
    if ($sr->worker && $user->id === $sr->worker->user_id) return true;
    // Demandas pendientes: cualquier usuario autenticado puede contactar al cliente
    return $sr->status === 'pending';
});

// Canal de tracking por solicitud (solo participantes)
Broadcast::channel('request.{serviceRequestId}', function ($user, $serviceRequestId) {
    $sr = \App\Models\ServiceRequest::find($serviceRequestId);
    if (!$sr) return false;
    return $user->id === $sr->client_id || ($sr->worker && $user->id === $sr->worker->user_id);
});

// Legacy channels
Broadcast::channel('workers.{workerId}', function ($user, $workerId) {
    return $user->id === (int) $workerId;
});

Broadcast::channel('map', function ($user) {
    return true;
});

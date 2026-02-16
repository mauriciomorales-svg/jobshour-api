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

// Canal de chat por solicitud (solo participantes)
Broadcast::channel('chat.{serviceRequestId}', function ($user, $serviceRequestId) {
    $sr = \App\Models\ServiceRequest::find($serviceRequestId);
    if (!$sr) return false;
    return $user->id === $sr->client_id || $user->id === $sr->worker->user_id;
});

// Legacy channels
Broadcast::channel('workers.{workerId}', function ($user, $workerId) {
    return $user->id === (int) $workerId;
});

Broadcast::channel('map', function ($user) {
    return true;
});

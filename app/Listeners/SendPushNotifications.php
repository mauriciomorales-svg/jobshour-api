<?php

namespace App\Listeners;

use App\Events\ServiceRequestCreated;
use App\Events\ServiceRequestUpdated;
use App\Events\NewMessage;
use App\Services\FCMService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendPushNotifications
{
    private FCMService $fcm;

    public function __construct(FCMService $fcm)
    {
        $this->fcm = $fcm;
    }

    /**
     * Nueva demanda publicada → notificar workers cercanos
     */
    public function handleDemandCreated(ServiceRequestCreated $event): void
    {
        $sr = $event->serviceRequest;

        if (!$sr->client_location) {
            Log::debug("[FCM-Listener] No client_location for SR #{$sr->id}");
            return;
        }

        // Extraer coordenadas
        $coords = DB::selectOne(
            "SELECT ST_Y(client_location::geometry) as lat, ST_X(client_location::geometry) as lng FROM service_requests WHERE id = ?",
            [$sr->id]
        );

        if (!$coords) return;

        $category = $sr->category?->display_name ?? 'Servicio';
        $price = $sr->offered_price ? '$' . number_format($sr->offered_price, 0, ',', '.') : '';
        $urgencyEmoji = match ($sr->urgency) {
            'high' => '🔴',
            'medium' => '🟡',
            default => '🟢',
        };

        $title = "{$urgencyEmoji} Nueva solicitud de {$category}";
        $body = $sr->description ?? 'Alguien necesita tu ayuda';
        if ($price) $body .= " · {$price}";

        $this->fcm->sendToNearbyWorkers(
            $coords->lat,
            $coords->lng,
            15, // 15km radio
            $title,
            $body,
            [
                'type' => 'new_demand',
                'demand_id' => (string) $sr->id,
                'lat' => (string) $coords->lat,
                'lng' => (string) $coords->lng,
            ],
            $sr->client_id // excluir al cliente que publicó
        );
    }

    /**
     * Demanda actualizada (tomada, completada, cancelada) → notificar al cliente
     */
    public function handleDemandUpdated(ServiceRequestUpdated $event): void
    {
        $sr = $event->serviceRequest;
        $client = $sr->client;

        if (!$client) return;

        $title = '';
        $body = '';
        $data = [
            'type' => 'demand_update',
            'demand_id' => (string) $sr->id,
            'status' => $sr->status,
        ];

        switch ($sr->status) {
            case 'taken':
            case 'accepted':
                $workerName = $sr->worker?->user?->name ?? 'Un socio';
                $title = '✅ ¡Alguien tomó tu solicitud!';
                $body = "{$workerName} aceptó tu pedido";
                break;

            case 'completed':
                $title = '🎉 Servicio completado';
                $body = 'Tu solicitud fue completada. ¡Califica al socio!';
                break;

            case 'cancelled':
                $title = '❌ Solicitud cancelada';
                $body = 'Tu solicitud fue cancelada';
                break;

            default:
                return;
        }

        $this->fcm->sendToUser($client, $title, $body, $data);
    }

    /**
     * Nuevo mensaje de chat → notificar al receptor
     */
    public function handleNewMessage(NewMessage $event): void
    {
        $message = $event->message;
        $sender = $message->sender;
        $recipientId = $message->recipient_id;

        if (!$recipientId || !$sender) return;

        $recipient = \App\Models\User::find($recipientId);
        if (!$recipient) return;

        $senderName = $sender->name ?? $sender->nickname ?? 'Alguien';

        $this->fcm->sendToUser(
            $recipient,
            "💬 {$senderName}",
            mb_substr($message->content ?? 'Te envió un mensaje', 0, 100),
            [
                'type' => 'new_message',
                'message_id' => (string) $message->id,
                'sender_id' => (string) $sender->id,
            ]
        );
    }
}

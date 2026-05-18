<?php

namespace App\Listeners;

use App\Events\NewMessage;
use App\Events\ServiceRequestCreated;
use App\Events\ServiceRequestUpdated;
use App\Mail\DemandCompletedMail;
use App\Mail\DemandTakenMail;
use App\Models\Notification;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\FCMService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPushNotifications
{
    public function __construct(
        private FCMService $fcm,
    ) {}

    /**
     * Demanda nueva: push geo (mapa/tienda) o push al worker asignado (take/booking).
     */
    public function handleDemandCreated(ServiceRequestCreated $event): void
    {
        $sr = $event->serviceRequest->loadMissing(['client', 'category', 'worker.user']);

        if ($sr->worker_id && $sr->worker?->user) {
            $this->notifyAssignedWorker($sr);

            return;
        }

        $this->notifyNearbyWorkersOfOpenDemand($sr);
    }

    public function handleDemandUpdated(ServiceRequestUpdated $event): void
    {
        $sr = $event->serviceRequest->loadMissing(['client', 'worker.user', 'category']);
        $client = $sr->client;
        $workerUser = $sr->worker?->user;
        $reason = (string) ($sr->cancellation_reason ?? '');

        if ($client) {
            $this->notifyClientOfStatusChange($sr, $client, $reason);
        }

        if ($workerUser && $sr->status === 'cancelled') {
            $this->notifyWorkerOfCancellation($sr, $workerUser, $reason);
        }
    }

    public function notifyPriceAdjustmentPending(ServiceRequest $sr): void
    {
        $client = $sr->client;
        if (! $client) {
            return;
        }

        $amount = number_format((float) $sr->adjusted_price, 0, ',', '.');
        $this->pushToUser(
            $client,
            'Ajuste de precio pendiente',
            "El profesional propone \${$amount}. Revisa y aprueba en la app.",
            [
                'type' => 'price_adjustment_pending',
                'request_id' => (string) $sr->id,
            ]
        );
    }

    public function notifyPriceAdjustmentApproved(ServiceRequest $sr): void
    {
        $workerUser = $sr->worker?->user;
        if (! $workerUser) {
            return;
        }

        $amount = number_format((float) $sr->adjusted_price, 0, ',', '.');
        $this->pushToUser(
            $workerUser,
            'Precio aprobado',
            "El cliente aprobó el ajuste a \${$amount}.",
            [
                'type' => 'price_adjustment_approved',
                'request_id' => (string) $sr->id,
            ]
        );
    }

    private function notifyAssignedWorker(ServiceRequest $sr): void
    {
        $workerUser = $sr->worker?->user;
        if (! $workerUser) {
            return;
        }

        $label = $this->serviceTypeLabel($sr);
        $clientName = $sr->client?->name ?? 'Cliente';

        $this->pushToUser(
            $workerUser,
            'Nueva solicitud de trabajo',
            "{$label} de {$clientName}",
            [
                'type' => 'new_request',
                'request_id' => (string) $sr->id,
                'client_id' => (string) $sr->client_id,
            ]
        );
    }

    private function notifyNearbyWorkersOfOpenDemand(ServiceRequest $sr): void
    {
        if (! $sr->client_location) {
            Log::debug("[FCM] No client_location for SR #{$sr->id}");

            return;
        }

        $coords = DB::selectOne(
            'SELECT ST_Y(client_location::geometry) as lat, ST_X(client_location::geometry) as lng FROM service_requests WHERE id = ?',
            [$sr->id]
        );

        if (! $coords) {
            return;
        }

        $label = $this->serviceTypeLabel($sr);
        $price = $sr->offered_price ? '$'.number_format((float) $sr->offered_price, 0, ',', '.') : '';
        $urgencyEmoji = match ($sr->urgency) {
            'high', 'urgent' => '🔴',
            'medium' => '🟡',
            default => '🟢',
        };

        $title = "{$urgencyEmoji} {$label} cerca de ti";
        $body = $sr->description ?? 'Alguien necesita tu ayuda';
        if ($price !== '') {
            $body .= " · {$price}";
        }

        $this->fcm->sendToNearbyWorkers(
            (float) $coords->lat,
            (float) $coords->lng,
            15,
            $title,
            $body,
            [
                'type' => 'new_demand',
                'demand_id' => (string) $sr->id,
                'request_id' => (string) $sr->id,
                'service_type' => (string) ($sr->type ?? 'fixed_job'),
                'lat' => (string) $coords->lat,
                'lng' => (string) $coords->lng,
            ],
            $sr->client_id
        );
    }

    private function notifyClientOfStatusChange(ServiceRequest $sr, User $client, string $reason): void
    {
        $workerName = $sr->worker?->user?->name ?? 'Un socio';
        $data = [
            'type' => 'demand_update',
            'request_id' => (string) $sr->id,
            'status' => $sr->status,
        ];

        $title = null;
        $body = null;
        $email = false;

        switch ($sr->status) {
            case 'taken':
            case 'accepted':
                $title = 'Trabajo aceptado';
                $body = "{$workerName} aceptó tu solicitud";
                $data['type'] = 'request_accepted';
                $email = true;
                break;

            case 'rejected':
                $title = 'Solicitud rechazada';
                $body = 'El profesional no puede atender tu solicitud en este momento';
                $data['type'] = 'request_rejected';
                break;

            case 'completed':
                $title = 'Servicio completado';
                $body = "Califica tu experiencia con {$workerName}";
                $data['type'] = 'request_completed';
                $email = true;
                break;

            case 'cancelled':
                if ($reason === 'auto_expired_worker_accept_window') {
                    $title = 'Sin confirmación a tiempo';
                    $body = "{$workerName} no aceptó dentro del plazo. Podés publicar de nuevo.";
                    $data['type'] = 'request_auto_expired_accept';
                } elseif ($reason === 'auto_inactivity_timeout') {
                    $title = 'Servicio cancelado por inactividad';
                    $body = 'El trabajo se cerró tras 30 min sin actividad. Podés contactar soporte si fue un error.';
                    $data['type'] = 'request_auto_inactivity';
                } else {
                    $title = 'Solicitud cancelada';
                    $body = 'Tu solicitud fue cancelada';
                    $data['type'] = 'request_cancelled';
                }
                break;

            default:
                return;
        }

        $this->pushToUser($client, $title, $body, $data);

        if ($email && $client->email) {
            try {
                match ($sr->status) {
                    'taken', 'accepted' => Mail::to($client->email)->queue(new DemandTakenMail($sr)),
                    'completed' => Mail::to($client->email)->queue(new DemandCompletedMail($sr)),
                    default => null,
                };
            } catch (\Exception $e) {
                Log::warning('[Email] Failed: '.$e->getMessage());
            }
        }
    }

    private function notifyWorkerOfCancellation(ServiceRequest $sr, User $workerUser, string $reason): void
    {
        $clientName = $sr->client?->name ?? 'Cliente';

        if ($reason === 'auto_expired_worker_accept_window') {
            $title = 'Plazo para aceptar vencido';
            $body = 'La solicitud se cerró porque no pulsaste Aceptar a tiempo.';
            $type = 'request_auto_expired_accept';
        } elseif ($reason === 'auto_inactivity_timeout') {
            $title = 'Trabajo cancelado por inactividad';
            $body = 'Se cerró tras 30 min sin actividad en chat o GPS.';
            $type = 'request_auto_inactivity';
        } else {
            $title = 'Solicitud cancelada';
            $body = "{$clientName} canceló la solicitud";
            $type = 'request_cancelled';
        }

        $this->pushToUser($workerUser, $title, $body, [
            'type' => $type,
            'request_id' => (string) $sr->id,
            'cancellation_reason' => $reason,
        ]);
    }

    public function handleNewMessage(NewMessage $event): void
    {
        $message = $event->message;
        $message->loadMissing('sender:id,name', 'serviceRequest.worker');

        $serviceRequest = $message->serviceRequest;
        $sender = $message->sender;
        if (! $serviceRequest || ! $sender) {
            return;
        }

        $recipientId = (int) $message->sender_id === (int) $serviceRequest->client_id
            ? $serviceRequest->worker?->user_id
            : $serviceRequest->client_id;

        if (! $recipientId || (int) $recipientId === (int) $message->sender_id) {
            return;
        }

        $recipient = User::find($recipientId);
        if (! $recipient) {
            return;
        }

        $senderName = $sender->name ?? 'Usuario';
        $preview = $message->type === 'image'
            ? 'Imagen'
            : mb_substr((string) ($message->body ?? ''), 0, 100);

        $this->pushToUser(
            $recipient,
            $senderName,
            $preview,
            [
                'type' => 'chat_message',
                'request_id' => (string) $serviceRequest->id,
                'message_id' => (string) $message->id,
                'sender_id' => (string) $sender->id,
            ]
        );
    }

    private function serviceTypeLabel(ServiceRequest $sr): string
    {
        $category = $sr->category?->display_name ?? 'Servicio';

        return match ($sr->type) {
            'ride_share' => 'Viaje · '.$category,
            'express_errand' => 'Mandado · '.$category,
            default => $category,
        };
    }

    private function pushToUser(User $user, string $title, string $body, array $data): void
    {
        if (! $this->fcm->sendToUser($user, $title, $body, $data)) {
            return;
        }

        try {
            Notification::create([
                'user_id' => $user->id,
                'type' => (string) ($data['type'] ?? 'push'),
                'title' => $title,
                'message' => $body,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Notify] Could not persist in-app notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

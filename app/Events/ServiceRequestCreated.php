<?php

namespace App\Events;

use App\Models\ServiceRequest;
use App\Services\FirebaseService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ServiceRequestCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ServiceRequest $serviceRequest) {}

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('demand-map'), // Canal público para Dashboard
        ];

        // Si tiene worker asignado, también notificar al worker
        if ($this->serviceRequest->worker_id && $this->serviceRequest->worker) {
            $channels[] = new PrivateChannel('worker.' . $this->serviceRequest->worker->user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'request.new';
    }

    public function broadcastWith(): array
    {
        $sr = $this->serviceRequest->load(['client:id,name,avatar', 'category:id,display_name,icon,color']);
        
        // Obtener coordenadas desde PostGIS
        $coords = DB::selectOne("
            SELECT ST_X(client_location::geometry) as lng, ST_Y(client_location::geometry) as lat 
            FROM service_requests 
            WHERE id = ?
        ", [$sr->id]);

        return [
            'id' => $sr->id,
            'type' => $sr->type ?? 'fixed_job',
            'client' => [
                'name' => $sr->client->name,
                'avatar' => $sr->client->avatar,
            ],
            'category' => $sr->category?->display_name,
            'category_color' => $sr->category?->color,
            'description' => $sr->description,
            'urgency' => $sr->urgency,
            'status' => $sr->status,
            'offered_price' => (int) $sr->offered_price,
            'expires_at' => $sr->expires_at?->toISOString(),
            'payload' => $sr->payload ?? [],
            'lat' => $coords->lat ?? null,
            'lng' => $coords->lng ?? null,
        ];
    }

    public function handle()
    {
        try {
            Log::info('[Push] ServiceRequestCreated handle started', ['request_id' => $this->serviceRequest->id]);
            
            $worker = $this->serviceRequest->worker;
            if (!$worker || !$worker->user) {
                Log::warning('[Push] No worker or user found');
                return;
            }

            $user = $worker->user;
            if (!$user->fcm_token) {
                Log::warning('[Push] Worker has no FCM token', ['worker_id' => $worker->id]);
                return;
            }

            Log::info('[Push] Sending FCM to worker', [
                'worker_id' => $worker->id,
                'fcm_token' => substr($user->fcm_token, 0, 20) . '...',
            ]);

            $firebase = new FirebaseService();
            $result = $firebase->sendToDevice(
                $user->fcm_token,
                '¡Nueva solicitud de trabajo!',
                ($this->serviceRequest->category?->display_name ?? 'Trabajo') . ' de ' . ($this->serviceRequest->client?->name ?? 'Cliente'),
                [
                    'type' => 'new_request',
                    'request_id' => (string)$this->serviceRequest->id,
                    'client_id' => (string)$this->serviceRequest->client_id,
                ]
            );
            
            if ($result) {
                Log::info('[Push] FCM send success', ['worker_id' => $worker->id]);
            } else {
                Log::warning('[Push] FCM send returned false', ['worker_id' => $worker->id]);
            }
        } catch (\Throwable $e) {
            // No bloquear la operación principal si FCM falla
            Log::warning('[Push] FCM send failed (non-critical)', [
                'error' => $e->getMessage(),
                'request_id' => $this->serviceRequest->id ?? null,
            ]);
            // No relanzar la excepción - esto es un servicio no crítico
        }
    }
}

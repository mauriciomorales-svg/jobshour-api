<?php

namespace App\Events;

use App\Models\ServiceRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
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

}

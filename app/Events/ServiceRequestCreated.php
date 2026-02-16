<?php

namespace App\Events;

use App\Models\ServiceRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceRequestCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ServiceRequest $serviceRequest) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('worker.' . $this->serviceRequest->worker->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'request.new';
    }

    public function broadcastWith(): array
    {
        $sr = $this->serviceRequest->load(['client:id,name,avatar', 'category:id,display_name,icon,color']);
        return [
            'id' => $sr->id,
            'client' => [
                'name' => $sr->client->name,
                'avatar' => $sr->client->avatar,
            ],
            'category' => $sr->category?->display_name,
            'category_color' => $sr->category?->color,
            'description' => $sr->description,
            'urgency' => $sr->urgency,
            'offered_price' => (int) $sr->offered_price,
            'expires_at' => $sr->expires_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Events;

use App\Models\ServiceRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceRequestUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ServiceRequest $serviceRequest) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('worker.' . $this->serviceRequest->worker->user_id),
            new PrivateChannel('user.' . $this->serviceRequest->client_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'request.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->serviceRequest->id,
            'status' => $this->serviceRequest->status,
            'final_price' => (int) $this->serviceRequest->final_price,
            'accepted_at' => $this->serviceRequest->accepted_at?->toISOString(),
            'completed_at' => $this->serviceRequest->completed_at?->toISOString(),
        ];
    }
}

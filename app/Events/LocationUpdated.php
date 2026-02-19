<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $serviceRequestId,
        public float $lat,
        public float $lng,
        public ?float $accuracy = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('request.' . $this->serviceRequestId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'service_request_id' => $this->serviceRequestId,
            'location' => [
                'lat' => $this->lat,
                'lng' => $this->lng,
                'accuracy' => $this->accuracy,
                'timestamp' => now()->toISOString(),
            ],
        ];
    }
}

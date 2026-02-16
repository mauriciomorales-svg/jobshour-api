<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkerPresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $workerId,
        public string $status,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('presence-zone');
    }

    public function broadcastAs(): string
    {
        return 'worker.presence';
    }

    public function broadcastWith(): array
    {
        return [
            'worker_id' => $this->workerId,
            'status' => $this->status,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'timestamp' => now()->toISOString(),
        ];
    }
}

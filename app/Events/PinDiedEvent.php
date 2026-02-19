<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PinDiedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $requestId;
    public $timestamp;

    public function __construct(int $requestId)
    {
        $this->requestId = $requestId;
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn()
    {
        return new Channel('demand-map');
    }

    public function broadcastAs()
    {
        return 'pin.died';
    }

    public function broadcastWith()
    {
        return [
            'request_id' => $this->requestId,
            'timestamp' => $this->timestamp,
        ];
    }
}

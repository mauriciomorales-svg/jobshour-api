<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DemandAlert implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $searchCount,
        public string $city,
        public string $categoryName,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('presence-zone');
    }

    public function broadcastAs(): string
    {
        return 'demand.alert';
    }

    public function broadcastWith(): array
    {
        $msg = $this->searchCount === 1
            ? "Alguien busca {$this->categoryName} en {$this->city}. ¡Actívate!"
            : "Hay {$this->searchCount} personas buscando {$this->categoryName} en {$this->city}. ¡Es momento de activarse!";

        return [
            'search_count' => $this->searchCount,
            'city' => $this->city,
            'category' => $this->categoryName,
            'message' => $msg,
            'timestamp' => now()->toISOString(),
        ];
    }
}

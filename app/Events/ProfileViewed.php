<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProfileViewed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $workerUserId,
        public string $viewerCity,
        public int $viewCount,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('worker.' . $this->workerUserId);
    }

    public function broadcastAs(): string
    {
        return 'profile.viewed';
    }

    public function broadcastWith(): array
    {
        return [
            'city' => $this->viewerCity,
            'view_count' => $this->viewCount,
            'message' => "Un vecino en {$this->viewerCity} está mirando tu perfil. ¿Te pones en Verde?",
            'timestamp' => now()->toISOString(),
        ];
    }
}

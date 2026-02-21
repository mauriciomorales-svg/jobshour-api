<?php

namespace App\Events;

use App\Models\Worker;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkerActiveUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $worker;
    public $isActive;
    public $status;

    public function __construct(Worker $worker, bool $isActive, string $status = 'active')
    {
        $this->worker = $worker;
        $this->isActive = $isActive;
        $this->status = $status;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('workers');
    }

    public function broadcastAs(): string
    {
        return 'worker.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'worker_id' => $this->worker->id,
            'user_id' => $this->worker->user_id,
            'lat' => $this->worker->current_lat,
            'lng' => $this->worker->current_lng,
            'is_active' => $this->isActive,
            'status' => $this->status,
            'category_id' => $this->worker->category_id,
            'nickname' => $this->worker->user->nickname ?? $this->worker->user->name,
            'avatar' => $this->worker->user->avatar,
        ];
    }
}

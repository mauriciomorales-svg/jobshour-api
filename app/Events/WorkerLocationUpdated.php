<?php

namespace App\Events;

use App\Models\Worker;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkerLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Worker $worker;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('map'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'worker.location';
    }

    public function broadcastWith(): array
    {
        return [
            'worker_id' => $this->worker->id,
            'user_id' => $this->worker->user_id,
            'name' => $this->worker->user->name,
            'location' => [
                'lat' => $this->worker->latitude,
                'lng' => $this->worker->longitude,
            ],
            'accuracy' => $this->worker->location_accuracy,
            'status' => $this->worker->availability_status,
        ];
    }
}

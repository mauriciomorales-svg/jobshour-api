<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageNotify implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $recipientUserId,
        public int $requestId,
        public int $messageId,
        public int $senderId,
        public string $senderName,
        public string $preview,
        public ?string $senderEmail = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.' . $this->recipientUserId);
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->requestId,
            'message_id' => $this->messageId,
            'sender_id' => $this->senderId,
            'sender_name' => $this->senderName,
            'sender_email' => $this->senderEmail,
            'preview' => $this->preview,
        ];
    }
}

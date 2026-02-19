<?php

namespace App\Events;

use App\Models\Message;
use App\Services\FirebaseService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.' . $this->message->service_request_id);
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'sender_name' => $this->message->sender->name,
            'sender_avatar' => $this->message->sender->avatar,
            'body' => $this->message->body,
            'type' => $this->message->type,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }

    public function handle()
    {
        Log::info('[Push] NewMessage handle started', ['message_id' => $this->message->id]);
        
        $serviceRequest = $this->message->serviceRequest;
        if (!$serviceRequest) {
            Log::warning('[Push] No serviceRequest found for message', ['message_id' => $this->message->id]);
            return;
        }

        // Determinar quién debe recibir el push (el otro participante)
        $recipientId = $this->message->sender_id === $serviceRequest->client_id
            ? $serviceRequest->worker?->user_id
            : $serviceRequest->client_id;

        Log::info('[Push] Determined recipient', [
            'message_id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'client_id' => $serviceRequest->client_id,
            'recipient_id' => $recipientId,
        ]);

        if (!$recipientId) {
            Log::warning('[Push] No recipient found');
            return;
        }

        $recipient = \App\Models\User::find($recipientId);
        if (!$recipient) {
            Log::warning('[Push] Recipient user not found', ['recipient_id' => $recipientId]);
            return;
        }
        
        if (!$recipient->fcm_token) {
            Log::warning('[Push] Recipient has no FCM token', ['recipient_id' => $recipientId]);
            return;
        }

        Log::info('[Push] Sending FCM to recipient', [
            'recipient_id' => $recipientId,
            'fcm_token' => substr($recipient->fcm_token, 0, 20) . '...',
        ]);

        try {
            $firebase = new FirebaseService();
            $result = $firebase->sendToDevice(
                $recipient->fcm_token,
                'Nuevo mensaje de ' . $this->message->sender->name,
                $this->message->body,
                [
                    'type' => 'chat_message',
                    'request_id' => (string)$serviceRequest->id,
                    'message_id' => (string)$this->message->id,
                    'sender_id' => (string)$this->message->sender_id,
                ]
            );
            Log::info('[Push] FCM send result', ['success' => $result]);
        } catch (\Throwable $e) {
            Log::error('[Push] FCM send failed', ['error' => $e->getMessage()]);
        }
    }
}

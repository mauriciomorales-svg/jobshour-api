<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\NewMessage;
use App\Events\ChatMessageNotify;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\FCMService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    private function canAccessRequestChat(ServiceRequest $serviceRequest, int $userId): bool
    {
        $isClient = (int) $serviceRequest->client_id === $userId;
        $isWorker = $serviceRequest->worker && (int) $serviceRequest->worker->user_id === $userId;

        // Mantener compatibilidad: demandas pendientes permiten primer contacto.
        $isPendingDemand = $serviceRequest->status === 'pending';

        return $isClient || $isWorker || $isPendingDemand;
    }

    public function threads(Request $request)
    {
        $user = $request->user();

        $threads = ServiceRequest::query()
            ->where(function ($q) use ($user) {
                $q->where('client_id', $user->id)
                    ->orWhereHas('worker', function ($wq) use ($user) {
                        $wq->where('user_id', $user->id);
                    });
            })
            ->with([
                'client:id,name,avatar,email',
                'worker.user:id,name,avatar,email',
                'messages' => function ($mq) {
                    $mq->select('id', 'service_request_id', 'sender_id', 'body', 'type', 'created_at', 'read_at')
                        ->latest('created_at');
                },
            ])
            ->latest('updated_at')
            ->limit(40)
            ->get()
            ->map(function (ServiceRequest $sr) use ($user) {
                $isWorker = $sr->worker && (int) $sr->worker->user_id === (int) $user->id;
                $other = $isWorker ? $sr->client : ($sr->worker?->user);
                $lastMessage = $sr->messages->first();

                $unreadCount = $sr->messages
                    ->where('sender_id', '!=', $user->id)
                    ->whereNull('read_at')
                    ->count();

                return [
                    'request_id' => $sr->id,
                    'description' => $sr->description,
                    'status' => $sr->status,
                    'last_message' => $lastMessage?->body,
                    'last_message_at' => $lastMessage?->created_at?->toISOString(),
                    'unread_count' => $unreadCount,
                    'other_person' => $other ? [
                        'name' => $other->name,
                        'avatar' => $other->avatar,
                        'email' => $other->email ?? null,
                    ] : null,
                ];
            })
            ->filter(fn ($item) => !empty($item['other_person']))
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $threads,
        ]);
    }

    public function messages(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();
        if (! $this->canAccessRequestChat($serviceRequest, (int) $user->id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autorizado',
            ], 403);
        }

        $messages = $serviceRequest->messages()
            ->with('sender:id,name,avatar,email')
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'sender_id' => $m->sender_id,
                'sender_name' => $m->sender->name,
                'sender_avatar' => $m->sender->avatar,
                'sender_email' => $m->sender->email,
                'body' => $m->body,
                'type' => $m->type,
                'read_at' => $m->read_at?->toISOString(),
                'created_at' => $m->created_at->toISOString(),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => $messages,
        ]);
    }

    public function send(Request $request, ServiceRequest $serviceRequest)
    {
        try {
            // Validar que el usuario puede enviar mensajes en esta solicitud
            $user = $request->user();
            $isClient = (int) $serviceRequest->client_id === (int) $user->id;
            $isWorker = $serviceRequest->worker && (int) $serviceRequest->worker->user_id === (int) $user->id;

            if (! $this->canAccessRequestChat($serviceRequest, (int) $user->id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No autorizado para enviar mensajes en esta solicitud'
                ], 403);
            }

            if (!in_array($serviceRequest->status, ['accepted', 'pending', 'in_progress'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat no disponible para esta solicitud'
                ], 422);
            }

            $hasImage = $request->hasFile('image');
            $body = $request->input('body', '');
            $type = 'text';

            if ($hasImage) {
                $validated = $request->validate([
                    'image' => 'required|image|max:5120', // 5MB max
                    'body' => 'nullable|string|max:500',
                ]);
                
                // Guardar imagen
                $imagePath = $request->file('image')->store('chat_images', 'public');
                $body = $body ?: 'Imagen compartida';
                $type = 'image';
                $body = json_encode([
                    'image_url' => asset('storage/' . $imagePath),
                    'caption' => $body,
                ]);
            } else {
                $validated = $request->validate([
                    'body' => 'required|string|max:1000|min:1',
                    'type' => 'nullable|in:text,image,location',
                ]);
                
                $body = trim($validated['body']);
                $type = $validated['type'] ?? 'text';
            }

            if (empty($body)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El mensaje no puede estar vacío'
                ], 422);
            }

            $message = Message::create([
                'service_request_id' => $serviceRequest->id,
                'sender_id' => $user->id,
                'body' => $body,
                'type' => $type,
            ]);

            $message->load('sender:id,name,avatar,email');

            // Intentar broadcast pero no fallar si falla
            try {
                $event = new NewMessage($message);
                broadcast($event)->toOthers();
                $event->handle();
            } catch (\Throwable $e) {
                Log::warning('ChatController::send - Error en broadcast', [
                    'service_request_id' => $serviceRequest->id,
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Push FCM al destinatario
            try {
                $recipientId = $isClient ? $serviceRequest->worker?->user_id : $serviceRequest->client_id;
                if ($recipientId && $recipientId !== $user->id) {
                    // Aviso realtime directo al canal de usuario (fallback de notificaciones de chat)
                    broadcast(new ChatMessageNotify(
                        recipientUserId: (int) $recipientId,
                        requestId: (int) $serviceRequest->id,
                        messageId: (int) $message->id,
                        senderId: (int) $user->id,
                        senderName: (string) ($user->name ?? 'Usuario'),
                        preview: (string) ($type === 'image' ? '📷 Imagen' : mb_substr($request->input('body', ''), 0, 100)),
                        senderEmail: $user->email ? (string) $user->email : null
                    ));

                    $recipient = User::find($recipientId);
                    if ($recipient?->fcm_token) {
                        $displayBody = $type === 'image' ? '📷 Imagen' : (strlen($body) > 80 ? substr($body, 0, 80) . '...' : $body);
                        (new FCMService())->sendToUser($recipient, $user->name, $displayBody, [
                            'type' => 'chat_message',
                            'request_id' => (string) $serviceRequest->id,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ChatController::send - Error en FCM push', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $message->id,
                    'sender_id' => $message->sender_id,
                    'sender_name' => $message->sender->name ?? 'Usuario',
                    'sender_avatar' => $message->sender->avatar ?? null,
                    'sender_email' => $message->sender->email ?? null,
                    'body' => $message->body,
                    'type' => $message->type,
                    'created_at' => $message->created_at->toISOString(),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('ChatController::send - Error crítico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $serviceRequest->id ?? null,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al enviar mensaje. Por favor intenta nuevamente.'
            ], 500);
        }
    }

    public function markRead(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();
        if (! $this->canAccessRequestChat($serviceRequest, (int) $user->id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autorizado',
            ], 403);
        }

        $serviceRequest->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}

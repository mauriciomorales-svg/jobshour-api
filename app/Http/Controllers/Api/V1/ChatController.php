<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function messages(Request $request, ServiceRequest $serviceRequest)
    {
        $messages = $serviceRequest->messages()
            ->with('sender:id,name,avatar')
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'sender_id' => $m->sender_id,
                'sender_name' => $m->sender->name,
                'sender_avatar' => $m->sender->avatar,
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
            $isClient = $serviceRequest->client_id === $user->id;
            $isWorker = $serviceRequest->worker && $serviceRequest->worker->user_id === $user->id;

            if (!$isClient && !$isWorker) {
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

            $message->load('sender:id,name,avatar');

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
                // Continuar aunque falle el broadcast
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $message->id,
                    'sender_id' => $message->sender_id,
                    'sender_name' => $message->sender->name ?? 'Usuario',
                    'sender_avatar' => $message->sender->avatar ?? null,
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
        $serviceRequest->messages()
            ->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}

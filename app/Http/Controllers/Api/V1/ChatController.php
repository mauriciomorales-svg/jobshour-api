<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;

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
        if (!in_array($serviceRequest->status, ['accepted', 'pending'])) {
            return response()->json(['error' => 'Chat no disponible para esta solicitud'], 422);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:1000',
            'type' => 'nullable|in:text,image,location',
        ]);

        $message = Message::create([
            'service_request_id' => $serviceRequest->id,
            'sender_id' => $request->user()->id,
            'body' => $validated['body'],
            'type' => $validated['type'] ?? 'text',
        ]);

        $message->load('sender:id,name,avatar');

        // TEMPORALMENTE DESHABILITADO - Reverb timeout
        // broadcast(new NewMessage($message))->toOthers();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender->name,
                'body' => $message->body,
                'type' => $message->type,
                'created_at' => $message->created_at->toISOString(),
            ],
        ], 201);
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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function($n) {
                return [
                    'id' => $n->id,
                    'type' => $n->type,
                    'title' => $n->title,
                    'message' => $n->message,
                    'read_at' => $n->read_at?->toISOString(),
                    'created_at' => $n->created_at->toISOString(),
                    'data' => $n->data,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $notifications,
        ]);
    }

    public function markAsRead(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autorizado'
            ], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Notificación marcada como leída',
        ]);
    }

    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'Todas las notificaciones marcadas como leídas',
        ]);
    }

    public function destroy(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autorizado'
            ], 403);
        }

        $notification->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Notificación eliminada',
        ]);
    }

    public function registerToken(Request $request)
    {
        $validated = $request->validate(['fcm_token' => 'required|string']);
        $request->user()->update([
            'fcm_token' => $validated['fcm_token'],
            'fcm_token_updated_at' => now(),
        ]);
        return response()->json(['status' => 'success', 'message' => 'Token registrado']);
    }

    public function preferences(Request $request)
    {
        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'preferences' => 'required|array',
                'preferences.*.type' => 'required|string',
                'preferences.*.enabled' => 'required|boolean',
                'preferences.*.push' => 'required|boolean',
                'preferences.*.email' => 'required|boolean',
            ]);

            DB::transaction(function() use ($request, $validated) {
                foreach ($validated['preferences'] as $pref) {
                    NotificationPreference::updateOrCreate(
                        [
                            'user_id' => $request->user()->id,
                            'type' => $pref['type'],
                        ],
                        [
                            'enabled' => $pref['enabled'],
                            'push' => $pref['push'],
                            'email' => $pref['email'],
                        ]
                    );
                }
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Preferencias guardadas',
            ]);
        }

        $preferences = NotificationPreference::where('user_id', $request->user()->id)
            ->get()
            ->map(function($p) {
                return [
                    'type' => $p->type,
                    'enabled' => $p->enabled,
                    'push' => $p->push,
                    'email' => $p->email,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $preferences,
        ]);
    }
}

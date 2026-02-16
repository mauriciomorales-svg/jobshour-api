<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function registerToken(Request $request)
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $user->update([
            'fcm_token' => $validated['fcm_token'],
            'fcm_token_updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'FCM token registered successfully',
        ]);
    }

    public function unregisterToken(Request $request)
    {
        $user = $request->user();
        $user->update([
            'fcm_token' => null,
            'fcm_token_updated_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'FCM token unregistered',
        ]);
    }
}

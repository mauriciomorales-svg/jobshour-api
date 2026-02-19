<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function registerToken(Request $request)
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string|max:255',
        ]);

        $user = $request->user();

        Log::info('[FCM] registerToken called', [
            'user_id' => $user?->id,
            'has_token' => isset($validated['fcm_token']) && $validated['fcm_token'] !== '',
            'token_prefix' => isset($validated['fcm_token']) ? substr($validated['fcm_token'], 0, 20) . '...' : null,
        ]);

        $user->update([
            'fcm_token' => $validated['fcm_token'],
            'fcm_token_updated_at' => now(),
        ]);

        Log::info('[FCM] registerToken saved', [
            'user_id' => $user?->id,
            'saved_token_prefix' => $user?->fcm_token ? substr($user->fcm_token, 0, 20) . '...' : null,
            'updated_at' => (string)($user?->fcm_token_updated_at),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'FCM token registered successfully',
        ]);
    }

    public function unregisterToken(Request $request)
    {
        $user = $request->user();

        Log::info('[FCM] unregisterToken called', [
            'user_id' => $user?->id,
        ]);

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

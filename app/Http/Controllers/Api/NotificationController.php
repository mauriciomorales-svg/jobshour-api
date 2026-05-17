<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirebaseService;
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

    /**
     * Proxy de registro FCM web cuando el navegador recibe 401 (p. ej. API key con referrer estricto).
     */
    public function registerFcmWeb(Request $request, FirebaseService $firebase)
    {
        $validated = $request->validate([
            'installation_auth_token' => 'required|string|min:20',
            'web' => 'required|array',
            'web.endpoint' => 'required|string|max:2048',
            'web.auth' => 'required|string|max:512',
            'web.p256dh' => 'required|string|max:512',
            'web.applicationPubKey' => 'nullable|string|max:256',
        ]);

        $web = $validated['web'];
        if (empty($web['applicationPubKey'])) {
            unset($web['applicationPubKey']);
        }

        $result = $firebase->registerWebPush($web, $validated['installation_auth_token']);
        if (!($result['ok'] ?? false)) {
            $httpStatus = (int) ($result['http_status'] ?? 502);
            $clientStatus = $httpStatus >= 400 && $httpStatus < 500 ? 422 : 502;

            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'No se pudo registrar el dispositivo en FCM',
                'fcm_error' => [
                    'http_status' => $httpStatus,
                    'details' => $result['details'] ?? null,
                ],
            ], $clientStatus);
        }

        $user = $request->user();
        $user->update([
            'fcm_token' => $result['token'],
            'fcm_token_updated_at' => now(),
        ]);

        Log::info('[FCM] registerFcmWeb saved', [
            'user_id' => $user->id,
            'token_prefix' => substr($result['token'], 0, 20).'...',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'FCM token registered successfully',
            'data' => ['fcm_token' => $result['token']],
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

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FCMService
{
    private ?string $serverKey;

    public function __construct()
    {
        $this->serverKey = config('services.fcm.server_key');
    }

    /**
     * Enviar push a un usuario específico
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (!$user->fcm_token) {
            Log::debug("[FCM] User {$user->id} has no FCM token");
            return false;
        }

        return $this->send($user->fcm_token, $title, $body, $data);
    }

    /**
     * Enviar push a múltiples usuarios
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): int
    {
        $tokens = User::whereIn('id', $userIds)
            ->whereNotNull('fcm_token')
            ->pluck('fcm_token')
            ->toArray();

        $sent = 0;
        foreach ($tokens as $token) {
            if ($this->send($token, $title, $body, $data)) {
                $sent++;
            }
        }

        Log::info("[FCM] Sent to {$sent}/" . count($tokens) . " users");
        return $sent;
    }

    /**
     * Enviar push a workers cercanos a una ubicación
     */
    public function sendToNearbyWorkers(float $lat, float $lng, float $radiusKm, string $title, string $body, array $data = [], ?int $excludeUserId = null): int
    {
        $query = DB::select("
            SELECT DISTINCT u.fcm_token
            FROM workers w
            JOIN users u ON u.id = w.user_id
            WHERE w.availability_status IN ('active', 'intermediate')
              AND u.fcm_token IS NOT NULL
              AND u.fcm_token != ''
              AND w.location IS NOT NULL
              AND ST_DWithin(
                  w.location::geography,
                  ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                  ?
              )
              " . ($excludeUserId ? "AND u.id != ?" : "") . "
        ", $excludeUserId
            ? [$lng, $lat, $radiusKm * 1000, $excludeUserId]
            : [$lng, $lat, $radiusKm * 1000]
        );

        $sent = 0;
        foreach ($query as $row) {
            if ($this->send($row->fcm_token, $title, $body, $data)) {
                $sent++;
            }
        }

        Log::info("[FCM] Sent to {$sent} nearby workers within {$radiusKm}km");
        return $sent;
    }

    /**
     * Enviar un push notification via FCM HTTP API
     */
    private function send(string $token, string $title, string $body, array $data = []): bool
    {
        if (!$this->serverKey) {
            Log::warning('[FCM] No server key configured. Set FCM_SERVER_KEY in .env');
            return false;
        }

        try {
            $payload = [
                'to' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'icon' => '/icon-192x192.png',
                    'click_action' => config('app.frontend_url', 'https://jobshour.dondemorales.cl'),
                ],
                'data' => array_merge($data, [
                    'title' => $title,
                    'body' => $body,
                    'timestamp' => now()->toIso8601String(),
                ]),
                'priority' => 'high',
            ];

            $ch = curl_init('https://fcm.googleapis.com/fcm/send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: key=' . $this->serverKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                Log::error("[FCM] HTTP {$httpCode}: {$response}");
                return false;
            }

            $result = json_decode($response, true);

            if (($result['failure'] ?? 0) > 0) {
                // Token inválido — limpiar
                $errorCode = $result['results'][0]['error'] ?? '';
                if (in_array($errorCode, ['NotRegistered', 'InvalidRegistration'])) {
                    User::where('fcm_token', $token)->update(['fcm_token' => null]);
                    Log::info("[FCM] Removed invalid token: {$errorCode}");
                }
                return false;
            }

            return ($result['success'] ?? 0) > 0;
        } catch (\Exception $e) {
            Log::error("[FCM] Exception: " . $e->getMessage());
            return false;
        }
    }
}

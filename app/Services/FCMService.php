<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class FCMService
{
    private ?string $credentialsPath;
    private ?string $projectId;

    public function __construct()
    {
        $this->credentialsPath = env('FIREBASE_CREDENTIALS')
            ? base_path(env('FIREBASE_CREDENTIALS'))
            : null;
        $this->projectId = env('FIREBASE_PROJECT_ID');
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
     * Obtener OAuth2 access token desde el service account JSON (Firebase Admin SDK)
     */
    private function getAccessToken(): ?string
    {
        // Cache del token por 50 minutos (expira en 60)
        return Cache::remember('fcm_access_token', 3000, function () {
            if (!$this->credentialsPath || !file_exists($this->credentialsPath)) {
                Log::error("[FCM] Service account file not found: {$this->credentialsPath}");
                return null;
            }

            $sa = json_decode(file_get_contents($this->credentialsPath), true);
            if (!$sa || !isset($sa['private_key'], $sa['client_email'])) {
                Log::error("[FCM] Invalid service account JSON");
                return null;
            }

            // Crear JWT
            $now = time();
            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = base64_encode(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $b64url = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $headerB64 = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claimsB64 = $b64url(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signInput = "{$headerB64}.{$claimsB64}";
            $privateKey = openssl_pkey_get_private($sa['private_key']);
            if (!$privateKey) {
                Log::error("[FCM] Failed to parse private key");
                return null;
            }

            openssl_sign($signInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            $jwt = "{$signInput}." . $b64url($signature);

            // Exchange JWT for access token
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]),
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                Log::error("[FCM] OAuth token exchange failed: HTTP {$httpCode} - {$response}");
                return null;
            }

            $result = json_decode($response, true);
            Log::info("[FCM] Access token obtained, expires in {$result['expires_in']}s");
            return $result['access_token'] ?? null;
        });
    }

    /**
     * Enviar push notification via FCM HTTP v1 API
     */
    private function send(string $token, string $title, string $body, array $data = []): bool
    {
        if (!$this->projectId) {
            Log::warning('[FCM] No FIREBASE_PROJECT_ID configured');
            return false;
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return false;
        }

        try {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'webpush' => [
                        'notification' => [
                            'icon' => '/icon-192x192.png',
                            'click_action' => config('app.frontend_url', 'https://jobshour.dondemorales.cl'),
                        ],
                    ],
                    'data' => array_map('strval', array_merge($data, [
                        'title' => $title,
                        'body' => $body,
                        'timestamp' => now()->toIso8601String(),
                    ])),
                    'android' => ['priority' => 'high'],
                ],
            ];

            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                Log::debug("[FCM] Push sent OK");
                return true;
            }

            $result = json_decode($response, true);
            $errorCode = $result['error']['details'][0]['errorCode'] ?? ($result['error']['status'] ?? 'UNKNOWN');

            // Token inválido — limpiar
            if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'])) {
                User::where('fcm_token', $token)->update(['fcm_token' => null]);
                Log::info("[FCM] Removed invalid token: {$errorCode}");
            } else {
                Log::error("[FCM] HTTP {$httpCode}: {$response}");
            }

            // Si token expiró, limpiar cache y reintentar una vez
            if ($httpCode === 401) {
                Cache::forget('fcm_access_token');
                Log::info("[FCM] Token expired, cleared cache");
            }

            return false;
        } catch (\Exception $e) {
            Log::error("[FCM] Exception: " . $e->getMessage());
            return false;
        }
    }
}

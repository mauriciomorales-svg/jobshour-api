<?php

namespace App\Services;

use App\Models\User;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    protected $projectId;
    protected $accessToken;
    protected $credentialsFile;

    public function __construct()
    {
        $this->projectId = config('firebase.project_id', 'jobshours');
        $credentialsFile = config('firebase.credentials.file');

        if (is_string($credentialsFile) && $credentialsFile !== '') {
            $isAbsoluteUnix = str_starts_with($credentialsFile, '/');
            $isAbsoluteWindows = preg_match('/^[A-Za-z]:\\\\/', $credentialsFile) === 1;

            if (!$isAbsoluteUnix && !$isAbsoluteWindows) {
                $credentialsFile = base_path($credentialsFile);
            }
        }

        $this->credentialsFile = $credentialsFile;

        Log::info('[FCM] FirebaseService initialized', [
            'project_id' => $this->projectId,
            'credentials_file' => $this->credentialsFile,
            'credentials_exists' => is_string($this->credentialsFile) ? file_exists($this->credentialsFile) : false,
        ]);
    }

    private function getAccessToken(): ?string
    {
        if (! is_string($this->credentialsFile) || ! file_exists($this->credentialsFile)) {
            Log::error('[FCM] Firebase credentials file not found');

            return null;
        }

        return Cache::remember('firebase_messaging_access_token', 3000, function () {
            try {
                $credentials = new ServiceAccountCredentials(
                    'https://www.googleapis.com/auth/firebase.messaging',
                    $this->credentialsFile,
                );
                $token = $credentials->fetchAuthToken();
                $accessToken = $token['access_token'] ?? null;

                if (! is_string($accessToken) || $accessToken === '') {
                    Log::error('[FCM] OAuth token missing in response', ['token' => $token]);

                    return null;
                }

                return $accessToken;
            } catch (\Throwable $e) {
                Log::error('[FCM] OAuth token request failed', [
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $frontendUrl = rtrim((string) config('app.frontend_url', 'https://jobshours.com'), '/');

        $message = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'webpush' => [
                    'notification' => [
                        'icon' => '/icon-192x192.png',
                    ],
                    'fcm_options' => [
                        'link' => $frontendUrl,
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

        $response = Http::withToken($token)->post($url, $message);

        if ($response->successful()) {
            return true;
        }

        $result = $response->json();
        $errorCode = $result['error']['details'][0]['errorCode']
            ?? ($result['error']['status'] ?? 'UNKNOWN');

        if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true)) {
            User::where('fcm_token', $deviceToken)->update(['fcm_token' => null]);
            Log::info('[FCM] Removed invalid token', ['error_code' => $errorCode]);
        } else {
            Log::error('[FCM] FCM send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'project_id' => $this->projectId,
                'error_code' => $errorCode,
            ]);
        }

        if ($response->status() === 401) {
            Cache::forget('firebase_messaging_access_token');
            Cache::forget('fcm_access_token');
        }

        return false;
    }

    /** VAPID por defecto del SDK web de Firebase (no enviar applicationPubKey si coincide). */
    private const DEFAULT_VAPID_KEY =
        'BDOU99-h67HcA6JeFXHbSNMu7e2yNNu3RzoMj8TM4W88jITfq7ZmPvIM1Iv-4_l2LxQcYwhqby2xGpWwzjfAnG4';

    /**
     * Registra push web en FCM (proxy servidor).
     *
     * @param  array{endpoint: string, auth: string, p256dh: string, applicationPubKey?: string}  $web
     * @return array{ok: true, token: string}|array{ok: false, http_status: int, message: string, details?: mixed}
     */
    public function registerWebPush(array $web, string $installationAuthToken): array
    {
        $apiKey = config('firebase.web_api_key');
        if (!is_string($apiKey) || $apiKey === '') {
            Log::error('[FCM] registerWebPush: FIREBASE_WEB_API_KEY missing');

            return [
                'ok' => false,
                'http_status' => 500,
                'message' => 'FIREBASE_WEB_API_KEY missing',
            ];
        }

        $installationAuthToken = trim(preg_replace('/^FIS\s+/i', '', $installationAuthToken) ?? '');
        $web = $this->normalizeWebPushPayload($web);

        $url = "https://fcmregistrations.googleapis.com/v1/projects/{$this->projectId}/registrations";
        $payloadVariants = [$web];
        if (isset($web['applicationPubKey'])) {
            $withoutPubKey = $web;
            unset($withoutPubKey['applicationPubKey']);
            $payloadVariants[] = $withoutPubKey;
        }

        $authModes = ['fis', 'bearer'];
        $lastError = ['http_status' => 502, 'message' => 'FCM registration failed', 'details' => null];

        foreach ($payloadVariants as $index => $variant) {
            foreach ($authModes as $authMode) {
                $headers = [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Goog-Api-Key' => $apiKey,
                ];
                if ($authMode === 'fis') {
                    $headers['x-goog-firebase-installations-auth'] = 'FIS '.$installationAuthToken;
                } else {
                    $headers['Authorization'] = 'Bearer '.$installationAuthToken;
                }

                $response = Http::withHeaders($headers)->post($url, ['web' => $variant]);

                if ($response->successful()) {
                    $token = $response->json('token');
                    if (is_string($token) && $token !== '') {
                        Log::info('[FCM] registerWebPush OK', [
                            'auth_mode' => $authMode,
                            'payload_variant' => $index,
                            'with_application_pub_key' => isset($variant['applicationPubKey']),
                        ]);

                        return ['ok' => true, 'token' => $token];
                    }
                }

                $error = $response->json('error') ?? [];
                $lastError = [
                    'http_status' => $response->status(),
                    'message' => is_string($error['message'] ?? null)
                        ? $error['message']
                        : 'FCM registration failed',
                    'details' => $error['details'] ?? $response->body(),
                ];

                Log::warning('[FCM] registerWebPush attempt failed', [
                    'auth_mode' => $authMode,
                    'payload_variant' => $index,
                    'with_application_pub_key' => isset($variant['applicationPubKey']),
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        }

        Log::error('[FCM] registerWebPush failed after retries', $lastError);

        return ['ok' => false, ...$lastError];
    }

    /**
     * @param  array{endpoint: string, auth: string, p256dh: string, applicationPubKey?: string}  $web
     * @return array{endpoint: string, auth: string, p256dh: string, applicationPubKey?: string}
     */
    private function normalizeWebPushPayload(array $web): array
    {
        $web['endpoint'] = trim($web['endpoint']);
        $web['auth'] = $this->normalizeBase64Url($web['auth']);
        $web['p256dh'] = $this->normalizeBase64Url($web['p256dh']);

        if (isset($web['applicationPubKey'])) {
            $pubKey = $this->normalizeBase64Url(trim($web['applicationPubKey']));
            if ($pubKey === '' || $pubKey === self::DEFAULT_VAPID_KEY) {
                unset($web['applicationPubKey']);
            } else {
                $web['applicationPubKey'] = $pubKey;
            }
        }

        return $web;
    }

    private function normalizeBase64Url(string $value): string
    {
        return rtrim(strtr(trim($value), '+/', '-_'), '=');
    }

    public function sendJobAccepted($clientToken, $workerName, $jobId)
    {
        return $this->sendToDevice($clientToken, '¡Trabajo aceptado!', "{$workerName} aceptó tu solicitud", ['type' => 'job_accepted', 'job_id' => (string)$jobId]);
    }

    public function sendPriceAdjustment($clientToken, $workerName, $newPrice, $jobId)
    {
        return $this->sendToDevice($clientToken, 'Ajuste de precio', "{$workerName} propone \${$newPrice}", ['type' => 'price_adjustment', 'job_id' => (string)$jobId]);
    }

    public function sendFavoriteJobAlert($workerToken, $employerName, $jobId)
    {
        return $this->sendToDevice($workerToken, '¡Trabajo prioritario!', "{$employerName} te necesita", ['type' => 'favorite_job', 'job_id' => (string)$jobId]);
    }
}

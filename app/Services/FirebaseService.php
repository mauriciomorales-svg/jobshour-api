<?php

namespace App\Services;

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

    private function getAccessToken()
    {
        if (!file_exists($this->credentialsFile)) {
            Log::error('Firebase credentials file not found');
            return null;
        }

        $credentials = json_decode(file_get_contents($this->credentialsFile), true);
        if (!$credentials) {
            Log::error('Invalid Firebase credentials');
            return null;
        }

        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $time = time();
        $payload = json_encode([
            'iss' => $credentials['client_email'],
            'sub' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $time,
            'exp' => $time + 3600,
        ]);

        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = '';
        if (!openssl_sign($base64Header . '.' . $base64Payload, $signature, $credentials['private_key'], 'SHA256')) {
            return null;
        }
        
        $jwt = $base64Header . '.' . $base64Payload . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (!$response->successful()) {
            Log::error('[FCM] OAuth token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $accessToken = $response->json('access_token');
        if (!$accessToken) {
            Log::error('[FCM] OAuth token missing in response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $accessToken;
    }

    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = [])
    {
        $token = $this->getAccessToken();
        if (!$token) return false;

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $message = [
            'message' => [
                'token' => $deviceToken,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
            ]
        ];

        $response = Http::withToken($token)->post($url, $message);

        if (!$response->successful()) {
            Log::error('[FCM] FCM send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'project_id' => $this->projectId,
            ]);
        }

        return $response->successful();
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

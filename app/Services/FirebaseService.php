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
        $this->credentialsFile = config('firebase.credentials.file');
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

        return $response->successful() ? $response->json('access_token') : null;
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

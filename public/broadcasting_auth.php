<?php
// Standalone broadcasting auth - bypasses Laravel middleware/opcache
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Accept, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Auth via Bearer token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenStr = str_replace('Bearer ', '', $authHeader);
$parts = explode('|', $tokenStr, 2);
$tokenId = $parts[0] ?? null;
$tokenValue = $parts[1] ?? null;

$accessToken = \Laravel\Sanctum\PersonalAccessToken::find($tokenId);
if (!$accessToken || !hash_equals($accessToken->token, hash('sha256', $tokenValue ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$user = $accessToken->tokenable;
if (!$user) {
    http_response_code(403);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Get Pusher credentials
$pusherKey = config('broadcasting.connections.pusher.key') ?: env('PUSHER_APP_KEY', '9a309a9f35c89457ea2c');
$pusherSecret = config('broadcasting.connections.pusher.secret') ?: env('PUSHER_APP_SECRET', '523f364233cbd50223e2');

// Read POST data
$socketId = $_POST['socket_id'] ?? '';
$channelName = $_POST['channel_name'] ?? '';

if (!$socketId || !$channelName) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing socket_id or channel_name']);
    exit;
}

// Validate channel access
$allowed = false;
if ($channelName === "private-worker.{$user->id}" || $channelName === "private-user.{$user->id}") {
    $allowed = true;
} elseif (preg_match('/^private-chat\.(\d+)$/', $channelName)) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'Channel not authorized']);
    exit;
}

// Generate Pusher auth signature
$stringToSign = "{$socketId}:{$channelName}";
$signature = hash_hmac('sha256', $stringToSign, $pusherSecret);

echo json_encode(['auth' => "{$pusherKey}:{$signature}"]);

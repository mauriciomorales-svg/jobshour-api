<?php
// Standalone cancel endpoint - reverts the original demand to pending
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

$requestId = $_GET['id'] ?? null;
if (!$requestId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing request id']);
    exit;
}

// Auth
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenStr = str_replace('Bearer ', '', $authHeader);
$parts = explode('|', $tokenStr, 2);
$tokenId = $parts[0] ?? null;
$tokenValue = $parts[1] ?? null;

$accessToken = \Laravel\Sanctum\PersonalAccessToken::find($tokenId);
if (!$accessToken || !hash_equals($accessToken->token, hash('sha256', $tokenValue ?? ''))) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token inválido']);
    exit;
}

$user = $accessToken->tokenable;

// Find the request (the one created by take_demand.php)
$serviceRequest = \App\Models\ServiceRequest::find($requestId);
if (!$serviceRequest) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Solicitud no encontrada']);
    exit;
}

// Verify this user is involved (as worker or client)
$worker = \App\Models\Worker::where('user_id', $user->id)->first();
$isWorker = $worker && $serviceRequest->worker_id === $worker->id;
$isClient = $serviceRequest->client_id === $user->id;

if (!$isWorker && !$isClient) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

// Only cancel if pending or accepted
if (!in_array($serviceRequest->status, ['pending', 'accepted'])) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Solo se pueden cancelar solicitudes pendientes o aceptadas']);
    exit;
}

try {
    \Illuminate\Support\Facades\DB::beginTransaction();

    // Find the original demand that spawned this request
    // Match by: same client_id, same description, status='accepted', and worker_id matches
    $originalDemand = \App\Models\ServiceRequest::where('client_id', $serviceRequest->client_id)
        ->where('description', $serviceRequest->description)
        ->where('status', 'accepted')
        ->where('worker_id', $serviceRequest->worker_id)
        ->where('id', '!=', $serviceRequest->id)
        ->first();

    if ($originalDemand) {
        // Revert original demand back to pending
        $originalDemand->update([
            'status' => 'pending',
            'worker_id' => null,
        ]);
    }

    // Cancel the service request
    $serviceRequest->update([
        'status' => 'cancelled',
    ]);

    \Illuminate\Support\Facades\DB::commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Solicitud cancelada. La demanda vuelve a estar disponible.',
        'demand_restored' => (bool) $originalDemand,
    ]);
} catch (\Exception $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

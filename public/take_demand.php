<?php
// Standalone take endpoint - NO opcache, NO middleware
// URL: /take_demand.php?id=10
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

$demandId = $_GET['id'] ?? null;
if (!$demandId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing demand id']);
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
    echo json_encode(['status' => 'error', 'message' => 'Token inválido', '_token_id' => $tokenId, '_found' => (bool)$accessToken]);
    exit;
}

$user = $accessToken->tokenable;

// Find demand
$demand = \App\Models\ServiceRequest::find($demandId);
if (!$demand) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Demanda no encontrada']);
    exit;
}

// Validations
if ($demand->status !== 'pending') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Demanda no disponible', '_status' => $demand->status]);
    exit;
}
if ($demand->worker_id) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Ya tomada', '_worker' => $demand->worker_id]);
    exit;
}

$worker = \App\Models\Worker::where('user_id', $user->id)->first();
if (!$worker) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Sin perfil worker', '_user_id' => $user->id]);
    exit;
}
if ($demand->client_id === $user->id) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'No puedes tomar tu propia demanda']);
    exit;
}

// Take it
try {
    \Illuminate\Support\Facades\DB::beginTransaction();
    // Mark original demand as accepted
    $demand->update([
        'status' => 'accepted',
        'worker_id' => $worker->id,
    ]);

    $newReq = \App\Models\ServiceRequest::create([
        'client_id' => $demand->client_id,
        'worker_id' => $worker->id,
        'category_id' => $demand->category_id,
        'type' => $demand->type ?? 'fixed_job',
        'category_type' => $demand->category_type ?? 'fixed',
        'description' => $demand->description,
        'urgency' => $demand->urgency ?? 'normal',
        'offered_price' => $demand->offered_price,
        'pickup_address' => $demand->pickup_address,
        'status' => 'pending',
        'expires_at' => now()->addMinutes(5),
    ]);
    \Illuminate\Support\Facades\DB::commit();

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => '✅ Has tomado esta demanda. El cliente tiene 5 minutos para confirmar.',
        'data' => $newReq->toArray(),
    ]);
} catch (\Exception $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

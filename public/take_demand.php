<?php
/**
 * Standalone take endpoint - NO opcache, NO middleware
 * URL: POST /take_demand.php?id=10
 *
 * Usa un UPDATE atómico (WHERE taken_by_worker_id IS NULL) para garantizar
 * que solo un worker puede tomar la demanda, incluso bajo concurrencia.
 */
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

$demand = \App\Models\ServiceRequest::find($demandId);
if (!$demand) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Demanda no encontrada']);
    exit;
}

// Pre-checks rápidos (sin bloqueo aún)
if ($demand->status !== 'pending') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Demanda no disponible', '_status' => $demand->status]);
    exit;
}
if ($demand->taken_by_worker_id !== null) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Esta demanda ya fue tomada por otro trabajador']);
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
if ($worker->availability_status === 'inactive') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Activa tu disponibilidad para tomar demandas']);
    exit;
}

$db = \Illuminate\Support\Facades\DB::getFacadeRoot();

try {
    $db->beginTransaction();

    // Mutex atómico: solo avanza si nadie más tomó la demanda.
    $locked = $db->update(
        "UPDATE service_requests
         SET taken_by_worker_id = ?, taken_at = NOW(), pin_expires_at = NOW()
         WHERE id = ? AND status = 'pending' AND taken_by_worker_id IS NULL",
        [$worker->id, $demand->id]
    );

    if ($locked === 0) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Esta demanda ya fue tomada por otro trabajador']);
        exit;
    }

    $newReq = \App\Models\ServiceRequest::create([
        'client_id'              => $demand->client_id,
        'worker_id'              => $worker->id,
        'category_id'            => $demand->category_id,
        'type'                   => $demand->type ?? 'fixed_job',
        'category_type'          => $demand->category_type ?? 'fixed',
        'description'            => $demand->description,
        'urgency'                => $demand->urgency ?? 'normal',
        'offered_price'          => $demand->offered_price,
        'pickup_address'         => $demand->pickup_address,
        'delivery_address'       => $demand->delivery_address,
        'pickup_lat'             => $demand->pickup_lat,
        'pickup_lng'             => $demand->pickup_lng,
        'delivery_lat'           => $demand->delivery_lat,
        'delivery_lng'           => $demand->delivery_lng,
        'carga_tipo'             => $demand->carga_tipo,
        'carga_peso'             => $demand->carga_peso,
        'status'                 => 'pending',
        'expires_at'             => now()->addMinutes(5),
        'derived_from_demand_id' => $demand->id,
    ]);

    // Copiar geolocalización si existe.
    if ($demand->client_location) {
        $loc = $db->selectOne(
            "SELECT ST_X(client_location::geometry) as lng, ST_Y(client_location::geometry) as lat
             FROM service_requests WHERE id = ?",
            [$demand->id]
        );
        if ($loc) {
            $db->update(
                "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$loc->lng, $loc->lat, $newReq->id]
            );
        }
    }

    $db->commit();

    http_response_code(201);
    echo json_encode([
        'status'  => 'success',
        'message' => '✅ Has tomado esta demanda. El cliente tiene 5 minutos para confirmar.',
        'data'    => $newReq->toArray(),
    ]);
} catch (\Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

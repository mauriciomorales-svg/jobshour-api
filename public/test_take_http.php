<?php
// Direct test endpoint - bypasses opcache
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

header('Content-Type: application/json');

$demandId = $_GET['id'] ?? 10;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

$result = ['step' => 'init', 'demand_id' => $demandId, 'auth' => substr($authHeader, 0, 20)];

// Parse token
$tokenStr = str_replace('Bearer ', '', $authHeader);
$parts = explode('|', $tokenStr, 2);
$tokenId = $parts[0] ?? null;
$tokenValue = $parts[1] ?? null;

// Find token
$accessToken = \Laravel\Sanctum\PersonalAccessToken::find($tokenId);
if ($accessToken && hash_equals($accessToken->token, hash('sha256', $tokenValue))) {
    $user = $accessToken->tokenable;
    $result['user'] = ['id' => $user->id, 'name' => $user->name];
} else {
    $result['error'] = 'Invalid token';
    $result['token_found'] = $accessToken ? true : false;
    echo json_encode($result);
    exit;
}

// Find demand
$demand = \App\Models\ServiceRequest::find($demandId);
if (!$demand) {
    $result['error'] = 'Demand not found';
    echo json_encode($result);
    exit;
}

$result['demand'] = [
    'id' => $demand->id,
    'status' => $demand->status,
    'worker_id' => $demand->worker_id,
    'client_id' => $demand->client_id,
];

// Find worker
$worker = \App\Models\Worker::where('user_id', $user->id)->first();
$result['worker'] = $worker ? ['id' => $worker->id, 'status' => $worker->availability_status] : null;

// Validations
$result['checks'] = [
    'status_pending' => $demand->status === 'pending',
    'no_worker' => $demand->worker_id === null,
    'not_own' => $demand->client_id !== $user->id,
    'has_worker' => $worker !== null,
    'not_inactive' => $worker ? $worker->availability_status !== 'inactive' : false,
];

// Try to take
if (array_search(false, $result['checks']) === false) {
    try {
        \Illuminate\Support\Facades\DB::beginTransaction();
        $newReq = \App\Models\ServiceRequest::create([
            'client_id' => $demand->client_id,
            'worker_id' => $worker->id,
            'category_id' => $demand->category_id,
            'type' => $demand->type ?? 'fixed_job',
            'category_type' => $demand->category_type ?? 'fixed',
            'description' => $demand->description,
            'urgency' => $demand->urgency ?? 'normal',
            'offered_price' => $demand->offered_price,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5),
        ]);
        \Illuminate\Support\Facades\DB::commit();
        $result['success'] = true;
        $result['new_request_id'] = $newReq->id;
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        $result['error'] = $e->getMessage();
    }
} else {
    $result['error'] = 'Validation failed';
}

echo json_encode($result, JSON_PRETTY_PRINT);

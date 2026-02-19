<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Simular exactamente lo que hace el take endpoint
$demandId = 10;
$tokenId = 31;

// 1. Buscar la demanda
$demand = \App\Models\ServiceRequest::find($demandId);
echo "DEMAND {$demandId}: " . ($demand ? "status={$demand->status} worker_id={$demand->worker_id}" : "NOT FOUND") . "\n";

// 2. Buscar el token y usuario
$token = \Laravel\Sanctum\PersonalAccessToken::find($tokenId);
echo "TOKEN {$tokenId}: " . ($token ? "user_id={$token->tokenable_id}" : "NOT FOUND") . "\n";

if ($token) {
    $user = \App\Models\User::find($token->tokenable_id);
    echo "USER: " . ($user ? "{$user->id} - {$user->name}" : "NOT FOUND") . "\n";
    
    $worker = \App\Models\Worker::where('user_id', $token->tokenable_id)->first();
    echo "WORKER: " . ($worker ? "id={$worker->id} status={$worker->availability_status}" : "NOT FOUND") . "\n";
}

// 3. Verificar todas las condiciones del take
echo "\n=== VALIDACIONES ===\n";
if ($demand) {
    echo "status !== pending? " . ($demand->status !== 'pending' ? 'FAIL (status=' . $demand->status . ')' : 'PASS') . "\n";
    echo "pin_expires_at past? " . (($demand->pin_expires_at && $demand->pin_expires_at->isPast()) ? 'FAIL' : 'PASS') . "\n";
    echo "worker_id set? " . ($demand->worker_id ? 'FAIL (worker_id=' . $demand->worker_id . ')' : 'PASS') . "\n";
    
    if ($token && $user) {
        echo "client_id === user_id? " . ($demand->client_id === $user->id ? 'FAIL (same user!)' : 'PASS') . "\n";
        if ($worker) {
            echo "worker inactive? " . ($worker->availability_status === 'inactive' ? 'FAIL' : 'PASS') . "\n";
        }
    }
}

// 4. Intentar tomar directamente
echo "\n=== INTENTANDO TOMAR ===\n";
if ($demand && $demand->status === 'pending' && !$demand->worker_id && $worker) {
    try {
        \Illuminate\Support\Facades\DB::beginTransaction();
        $newRequest = \App\Models\ServiceRequest::create([
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
        echo "SUCCESS! New request ID: {$newRequest->id}\n";
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        echo "ERROR: " . $e->getMessage() . "\n";
    }
} else {
    echo "SKIPPED - conditions not met\n";
}

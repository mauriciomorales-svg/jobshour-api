<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Worker;
use App\Models\ServiceRequest;
use App\Models\Message;
use App\Models\Category;

// Buscar usuarios existentes o crear nuevos con datos únicos
$timestamp = time();

$clientUser = User::where('email', 'like', 'cliente_sim_%@test.com')->first();
if (!$clientUser) {
    $clientUser = User::create([
        'name' => 'Cliente Sim ' . $timestamp,
        'email' => "cliente_sim_{$timestamp}@test.com",
        'password' => bcrypt('password'),
        'phone' => '+569000' . rand(10000, 99999),
    ]);
    echo "✅ Cliente creado: {$clientUser->name} (ID: {$clientUser->id})\n";
} else {
    echo "✓ Cliente existente: {$clientUser->name} (ID: {$clientUser->id})\n";
}

// Buscar o crear worker
$workerUser = User::where('email', 'like', 'worker_sim_%@test.com')->first();
if (!$workerUser) {
    $workerUser = User::create([
        'name' => 'Worker Sim ' . $timestamp,
        'email' => "worker_sim_{$timestamp}@test.com",
        'password' => bcrypt('password'),
        'phone' => '+569999' . rand(10000, 99999),
    ]);
    echo "✅ Worker usuario creado: {$workerUser->name} (ID: {$workerUser->id})\n";
} else {
    echo "✓ Worker usuario existente: {$workerUser->name} (ID: {$workerUser->id})\n";
}

// Buscar o crear worker profile
$worker = Worker::where('user_id', $workerUser->id)->first();
if (!$worker) {
    $worker = Worker::create([
        'user_id' => $workerUser->id,
        'category_id' => 1,
        'hourly_rate' => 15000,
        'bio' => 'Worker de prueba para simulación',
        'availability_status' => 'active',
        'current_lat' => -37.6672,
        'current_lng' => -72.5730,
        'current_city' => 'Renaico',
    ]);
    echo "✅ Worker profile creado (ID: {$worker->id})\n";
} else {
    echo "✓ Worker profile existente (ID: {$worker->id})\n";
}

// Buscar o crear solicitud de servicio
$serviceRequest = ServiceRequest::where('client_id', $clientUser->id)
    ->where('worker_id', $worker->id)
    ->whereIn('status', ['accepted', 'pending', 'in_progress'])
    ->first();

if (!$serviceRequest) {
    $category = Category::first() ?? Category::create(['name' => 'Test', 'slug' => 'test']);
    
    $serviceRequest = ServiceRequest::create([
        'client_id' => $clientUser->id,
        'worker_id' => $worker->id,
        'category_id' => $category->id,
        'type' => 'express_errand',
        'description' => 'Solicitud de prueba para simulación de chat',
        'status' => 'accepted',
        'urgency' => 'normal',
        'offered_price' => 15000,
        'accepted_at' => now(),
    ]);
    echo "✅ Solicitud de servicio creada (ID: {$serviceRequest->id})\n";
} else {
    echo "✓ Solicitud existente (ID: {$serviceRequest->id})\n";
}

// Mensajes para simular conversación
$conversation = [
    ['sender_id' => $clientUser->id, 'body' => 'Hola, ¿estás disponible para ayudarme con un mandado?'],
    ['sender_id' => $workerUser->id, 'body' => '¡Hola! Sí, claro. ¿Qué necesitas?'],
    ['sender_id' => $clientUser->id, 'body' => 'Necesito que vayas a la ferretería y compres unas cosas'],
    ['sender_id' => $workerUser->id, 'body' => 'Perfecto, ¿tienes la lista?'],
    ['sender_id' => $clientUser->id, 'body' => 'Sí: 1 martillo, 2 kilos de clavos y una brocha'],
    ['sender_id' => $workerUser->id, 'body' => 'Entendido. ¿A qué dirección te lo llevo?'],
    ['sender_id' => $clientUser->id, 'body' => 'Santiago Watt 205, Renaico'],
    ['sender_id' => $workerUser->id, 'body' => 'Perfecto, estoy a 5 minutos. Voy en camino.'],
    ['sender_id' => $clientUser->id, 'body' => '¡Excelente! Te espero.'],
    ['sender_id' => $workerUser->id, 'body' => 'Ya llegué a la ferretería, compro las cosas y voy para allá.'],
];

echo "\n💬 Iniciando simulación de chat...\n";
echo "═══════════════════════════════════════════════════\n";

$messageCount = 0;
foreach ($conversation as $msg) {
    $message = Message::create([
        'service_request_id' => $serviceRequest->id,
        'sender_id' => $msg['sender_id'],
        'body' => $msg['body'],
        'type' => 'text',
    ]);
    
    $sender = $msg['sender_id'] === $clientUser->id ? '👤 Cliente' : '👷 Worker';
    echo "{$sender}: {$msg['body']}\n";
    
    $messageCount++;
    usleep(500000); // 0.5 segundos entre mensajes
}

echo "═══════════════════════════════════════════════════\n";
echo "✅ Simulación completada: {$messageCount} mensajes enviados\n";
echo "📋 ServiceRequest ID: {$serviceRequest->id}\n";
echo "👤 Cliente ID: {$clientUser->id}\n";
echo "👷 Worker ID: {$worker->id}\n";

echo "\n💡 Para ver los mensajes en el frontend:\n";
echo "   1. Inicia sesión como cliente o worker\n";
echo "   2. Ve a la solicitud #{$serviceRequest->id}\n";
echo "   3. Abre el chat\n";
echo "\n📝 Tokens de prueba (para API):\n";
// Crear tokens personal access
$clientToken = $clientUser->createToken('simulation-client')->plainTextToken;
$workerToken = $workerUser->createToken('simulation-worker')->plainTextToken;
echo "   Cliente Token: {$clientToken}\n";
echo "   Worker Token: {$workerToken}\n";

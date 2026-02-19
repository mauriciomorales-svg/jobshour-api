<?php
// Script para crear relación de amistad entre dos usuarios para probar el chat
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Friendship;
use App\Models\Worker;

// IDs de los usuarios que necesitas para probar el chat
$userId1 = 61; // USACH
$userId2 = 62; // Comercial Isabel

echo "🔍 Verificando usuarios y workers...\n\n";

// Verificar que ambos usuarios existan
$user1 = DB::table('users')->where('id', $userId1)->first();
$user2 = DB::table('users')->where('id', $userId2)->first();

if (!$user1) {
    echo "❌ Usuario ID $userId1 no encontrado\n";
    exit(1);
}
if (!$user2) {
    echo "❌ Usuario ID $userId2 no encontrado\n";
    exit(1);
}

echo "✅ Usuario 1: {$user1->name} ({$user1->email})\n";
echo "✅ Usuario 2: {$user2->name} ({$user2->email})\n\n";

// Verificar que ambos tengan workers
$worker1 = Worker::where('user_id', $userId1)->first();
$worker2 = Worker::where('user_id', $userId2)->first();

if (!$worker1) {
    echo "⚠️ Usuario 1 no tiene worker. Creando worker...\n";
    $worker1 = Worker::create([
        'user_id' => $userId1,
        'is_visible' => true,
        'availability_status' => 'active',
        'hourly_rate' => 10000,
    ]);
    echo "✅ Worker creado para usuario 1 (ID: {$worker1->id})\n";
} else {
    // Asegurar que is_visible = true
    if (!$worker1->is_visible) {
        $worker1->is_visible = true;
        $worker1->save();
        echo "✅ Worker 1 ahora es visible\n";
    }
    echo "✅ Worker 1 existe (ID: {$worker1->id}, visible: " . ($worker1->is_visible ? 'sí' : 'no') . ")\n";
}

if (!$worker2) {
    echo "⚠️ Usuario 2 no tiene worker. Creando worker...\n";
    $worker2 = Worker::create([
        'user_id' => $userId2,
        'is_visible' => true,
        'availability_status' => 'active',
        'hourly_rate' => 10000,
    ]);
    echo "✅ Worker creado para usuario 2 (ID: {$worker2->id})\n";
} else {
    // Asegurar que is_visible = true
    if (!$worker2->is_visible) {
        $worker2->is_visible = true;
        $worker2->save();
        echo "✅ Worker 2 ahora es visible\n";
    }
    echo "✅ Worker 2 existe (ID: {$worker2->id}, visible: " . ($worker2->is_visible ? 'sí' : 'no') . ")\n";
}

echo "\n🔍 Verificando relación de amistad...\n";

// Verificar si ya existe una relación
$existingFriendship = Friendship::where(function($q) use ($userId1, $userId2) {
    $q->where('requester_id', $userId1)->where('addressee_id', $userId2);
})->orWhere(function($q) use ($userId1, $userId2) {
    $q->where('requester_id', $userId2)->where('addressee_id', $userId1);
})->first();

if ($existingFriendship) {
    if ($existingFriendship->status === 'accepted') {
        echo "✅ Ya son amigos (ID amistad: {$existingFriendship->id})\n";
    } else {
        echo "⚠️ Existe relación pero con estado: {$existingFriendship->status}\n";
        echo "   Actualizando a 'accepted'...\n";
        $existingFriendship->status = 'accepted';
        $existingFriendship->accepted_at = now();
        $existingFriendship->save();
        echo "✅ Relación actualizada a 'accepted'\n";
    }
} else {
    echo "⚠️ No existe relación de amistad. Creando...\n";
    $friendship = Friendship::create([
        'requester_id' => $userId1,
        'addressee_id' => $userId2,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    echo "✅ Relación de amistad creada (ID: {$friendship->id})\n";
}

echo "\n✅ ¡Listo! Ambos usuarios deberían aparecer en el chat ahora.\n";
echo "   - Usuario 1 (USACH): {$user1->name}\n";
echo "   - Usuario 2 (Comercial Isabel): {$user2->name}\n";

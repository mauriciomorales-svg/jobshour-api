<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Worker;

$email = 'mauricio.morales@usach.cl';

echo "Buscando usuario $email...\n";

$user = User::where('email', $email)->first();

if (!$user) {
    echo "❌ Usuario no encontrado\n";
    exit(1);
}

echo "✓ Usuario encontrado: {$user->name} (ID: {$user->id})\n";
echo "  Avatar actual: " . ($user->avatar ?? 'sin avatar') . "\n";

// Verificar si ya es worker
$existingWorker = Worker::where('user_id', $user->id)->first();

if ($existingWorker) {
    echo "✓ Ya es worker (ID: {$existingWorker->id})\n";
    echo "  Estado: {$existingWorker->availability_status}\n";
    exit(0);
}

// Convertir en worker - Renaico Centro
$user->lat = -37.6672;
$user->lng = -72.5730;
$user->nickname = 'ElJefe';
$user->save();

$worker = Worker::create([
    'user_id' => $user->id,
    'availability_status' => 'active',
    'hourly_rate' => 10000,
]);

echo "\n✅ CONVERTIDO EN WORKER\n";
echo "Worker ID: {$worker->id}\n";
echo "Estado: active (VERDE)\n";
echo "Ubicación: Renaico Centro (-37.6672, -72.5730)\n";
echo "Nickname: @ElJefe\n";
echo "Avatar conservado: " . ($user->avatar ?? 'sin avatar') . "\n";

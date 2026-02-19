<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Worker;

$email = 'mauricio.morales@usach.cl';

$user = User::where('email', $email)->first();

if (!$user) {
    echo "❌ Usuario no encontrado\n";
    exit(1);
}

echo "=== USUARIO ===\n";
echo "ID: {$user->id}\n";
echo "Nombre: {$user->name}\n";
echo "Email: {$user->email}\n";
echo "Lat: " . ($user->lat ?? 'NULL') . "\n";
echo "Lng: " . ($user->lng ?? 'NULL') . "\n";
echo "Avatar: " . ($user->avatar ?? 'NULL') . "\n";
echo "Nickname: " . ($user->nickname ?? 'NULL') . "\n";

$worker = Worker::where('user_id', $user->id)->first();

if ($worker) {
    echo "\n=== WORKER ===\n";
    echo "ID: {$worker->id}\n";
    echo "Estado: {$worker->availability_status}\n";
    echo "Tarifa: \${$worker->hourly_rate}\n";
} else {
    echo "\n❌ NO ES WORKER\n";
}

<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Limpiando sistema...\n\n";

// Eliminar solicitudes
$requests = DB::table('service_requests')->delete();
echo "✓ Solicitudes eliminadas: $requests\n";

// Eliminar workers
$workers = DB::table('workers')->delete();
echo "✓ Workers eliminados: $workers\n";

// Eliminar users (excepto admin si existe)
$users = DB::table('users')->where('email', '!=', 'admin@jobshour.cl')->delete();
echo "✓ Users eliminados: $users\n";

// Verificar
$requestsLeft = DB::table('service_requests')->count();
$workersLeft = DB::table('workers')->count();
$usersLeft = DB::table('users')->count();

echo "\n=== SISTEMA LIMPIO ===\n";
echo "Solicitudes restantes: $requestsLeft\n";
echo "Workers restantes: $workersLeft\n";
echo "Users restantes: $usersLeft\n";

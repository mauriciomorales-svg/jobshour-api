<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$withWorker = DB::table('service_requests')->whereNotNull('worker_id')->count();
$withoutWorker = DB::table('service_requests')->whereNull('worker_id')->count();
$total = DB::table('service_requests')->count();

echo "Total solicitudes: $total\n";
echo "Con worker_id: $withWorker\n";
echo "Sin worker_id: $withoutWorker\n";

// Eliminar las que no tienen worker_id
if ($withoutWorker > 0) {
    $deleted = DB::table('service_requests')->whereNull('worker_id')->delete();
    echo "\nSolicitudes sin worker_id eliminadas: $deleted\n";
    echo "Solicitudes restantes: " . DB::table('service_requests')->count() . "\n";
}

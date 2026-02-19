<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$deleted = DB::table('service_requests')->whereNull('worker_id')->delete();
echo "Solicitudes eliminadas: $deleted\n";
echo "Solicitudes restantes: " . DB::table('service_requests')->count() . "\n";

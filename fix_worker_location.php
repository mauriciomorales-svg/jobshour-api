<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$workerId = 19; // Mauricio
$lat = -37.6672; // Renaico
$lng = -72.5730;

echo "Actualizando ubicación del worker $workerId...\n";

// Actualizar location con PostGIS
DB::statement(
    "UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
    [$lng, $lat, $workerId]
);

// Actualizar nickname en users
DB::table('users')->where('id', 61)->update(['nickname' => 'ElJefe']);

echo "✅ UBICACIÓN ACTUALIZADA\n";
echo "Worker ID: $workerId\n";
echo "Lat: $lat\n";
echo "Lng: $lng\n";
echo "Ubicación: Renaico Centro\n";
echo "Nickname: @ElJefe\n";

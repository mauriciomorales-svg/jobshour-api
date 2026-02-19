<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Renaico Centro
$lat1 = -37.6672;
$lng1 = -72.5730;

// Angol
$lat2 = -37.8000;
$lng2 = -72.7000;

$distance = DB::selectOne("
    SELECT ST_Distance(
        ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
        ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
    ) / 1000 as distance_km
", [$lng1, $lat1, $lng2, $lat2]);

echo "Distancia Renaico → Angol: " . round($distance->distance_km, 2) . " km\n";
echo "\nWorkers intermediate solo aparecen si están a menos de 5km\n";
echo "María González (Angol) está a " . round($distance->distance_km, 2) . "km, por eso NO aparece\n";

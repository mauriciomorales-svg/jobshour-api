<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== TODOS LOS WORKERS ===\n\n";

$workers = DB::select("
    SELECT w.id, u.name, w.availability_status, 
           ST_X(w.location::geometry) as lng, 
           ST_Y(w.location::geometry) as lat
    FROM workers w
    JOIN users u ON w.user_id = u.id
    ORDER BY w.id
");

foreach ($workers as $w) {
    $status = $w->availability_status ?? 'NULL';
    $lat = $w->lat ?? 'NULL';
    $lng = $w->lng ?? 'NULL';
    echo "ID: {$w->id} | {$w->name} | Status: {$status} | Lat: {$lat}, Lng: {$lng}\n";
}

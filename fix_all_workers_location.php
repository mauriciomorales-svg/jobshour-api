<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Actualizando ubicaciones de workers...\n\n";

// Worker 16 - Juan Pérez - Renaico Centro - ACTIVE
DB::statement("UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?", [-72.5730, -37.6672, 16]);
echo "✓ Worker 16 (Juan Pérez) - Renaico Centro - ACTIVE\n";

// Worker 17 - María González - Angol - INTERMEDIATE
DB::statement("UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?", [-72.7000, -37.8000, 17]);
echo "✓ Worker 17 (María González) - Angol - INTERMEDIATE\n";

// Worker 18 - Pedro López - Entre Renaico-Angol - INACTIVE (NO DEBERÍA APARECER)
DB::statement("UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?", [-72.6400, -37.7300, 18]);
echo "✓ Worker 18 (Pedro López) - Entre Renaico-Angol - INACTIVE\n";

echo "\n✅ UBICACIONES ACTUALIZADAS\n";
echo "Nota: Worker 18 (inactive) NO debería aparecer en el mapa\n";

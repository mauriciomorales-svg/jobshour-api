<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== COLUMNAS DE LA TABLA USERS ===\n";

$columns = DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'users' ORDER BY ordinal_position");

foreach ($columns as $col) {
    echo "{$col->column_name} ({$col->data_type})\n";
}

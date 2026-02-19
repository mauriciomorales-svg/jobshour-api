<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== COLUMNAS DE LA TABLA WORKERS ===\n";

$columns = DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'workers' ORDER BY ordinal_position");

foreach ($columns as $col) {
    echo "{$col->column_name} ({$col->data_type})\n";
}

echo "\n=== WORKER ID 19 (Mauricio) ===\n";
$worker = DB::table('workers')->where('id', 19)->first();
if ($worker) {
    foreach ($worker as $key => $value) {
        echo "$key: " . ($value ?? 'NULL') . "\n";
    }
}

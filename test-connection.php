<?php
// Test de conexión a base de datos
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    echo "🧪 Probando conexión a PostgreSQL...\n";
    $pdo = DB::connection()->getPdo();
    echo "✅ Conexión OK\n\n";
    
    echo "🧪 Probando tabla categories...\n";
    $count = DB::table('categories')->count();
    echo "✅ Categories: $count registros\n\n";
    
    echo "🧪 Probando tabla workers...\n";
    $count = DB::table('workers')->count();
    echo "✅ Workers: $count registros\n\n";
    
    echo "🧪 Probando tabla nudges...\n";
    $count = DB::table('nudges')->count();
    echo "✅ Nudges: $count registros\n\n";
    
    echo "✅ TODAS LAS PRUEBAS PASARON\n";
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    exit(1);
}

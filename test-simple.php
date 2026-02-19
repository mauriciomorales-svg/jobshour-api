<?php
/**
 * Script simple para probar si PHP funciona en el servidor
 * Acceder desde: https://jobshour.dondemorales.cl/test-simple.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== TEST SIMPLE ===\n\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Current Directory: " . __DIR__ . "\n";
echo "File exists check:\n";

$files = [
    'vendor/autoload.php',
    'bootstrap/app.php',
    '.env',
    'storage/logs',
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    $exists = file_exists($path) || is_dir($path);
    echo "  " . ($exists ? "✅" : "❌") . " $file\n";
}

echo "\n=== TRYING TO LOAD LARAVEL ===\n";

try {
    require __DIR__ . '/vendor/autoload.php';
    echo "✅ Autoloader loaded\n";
    
    $app = require __DIR__ . '/bootstrap/app.php';
    echo "✅ Bootstrap loaded\n";
    
    echo "\n✅ Laravel está funcionando correctamente\n";
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Line: " . $e->getLine() . "\n";
    echo "\n   Trace:\n" . $e->getTraceAsString() . "\n";
}

<?php
/**
 * Script de diagnóstico para identificar problemas en el servidor
 * Ejecutar desde: https://jobshour.dondemorales.cl/diagnostico-servidor.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔍 Diagnóstico del Servidor JobsHour</h1>";
echo "<pre>";

// 1. Información PHP
echo "=== INFORMACIÓN PHP ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Error Reporting: " . error_reporting() . "\n";
echo "Display Errors: " . ini_get('display_errors') . "\n";
echo "Log Errors: " . ini_get('log_errors') . "\n";
echo "Error Log: " . ini_get('error_log') . "\n";
echo "\n";

// 2. Verificar autoloader
echo "=== AUTOLOADER ===\n";
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    echo "✅ vendor/autoload.php existe\n";
    require $autoloadPath;
    echo "✅ Autoloader cargado\n";
} else {
    echo "❌ vendor/autoload.php NO existe\n";
    exit;
}
echo "\n";

// 3. Verificar bootstrap
echo "=== BOOTSTRAP ===\n";
$bootstrapPath = __DIR__ . '/bootstrap/app.php';
if (file_exists($bootstrapPath)) {
    echo "✅ bootstrap/app.php existe\n";
    try {
        $app = require $bootstrapPath;
        echo "✅ Bootstrap cargado\n";
    } catch (\Exception $e) {
        echo "❌ Error al cargar bootstrap: " . $e->getMessage() . "\n";
        echo "   Archivo: " . $e->getFile() . "\n";
        echo "   Línea: " . $e->getLine() . "\n";
        exit;
    }
} else {
    echo "❌ bootstrap/app.php NO existe\n";
    exit;
}
echo "\n";

// 4. Verificar base de datos
echo "=== BASE DE DATOS ===\n";
try {
    $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    $db = $app->make('db');
    $connection = $db->connection();
    echo "✅ Conexión a BD establecida\n";
    
    // Contar tablas
    $tables = $connection->select("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = 'public'");
    echo "   Tablas en BD: " . $tables[0]->count . "\n";
    
    // Contar categorías
    $categories = $connection->table('categories')->where('is_active', true)->count();
    echo "   Categorías activas: " . $categories . "\n";
    
    // Contar workers
    $workers = $connection->table('workers')->count();
    echo "   Workers totales: " . $workers . "\n";
    
} catch (\Exception $e) {
    echo "❌ Error de BD: " . $e->getMessage() . "\n";
    echo "   Archivo: " . $e->getFile() . "\n";
    echo "   Línea: " . $e->getLine() . "\n";
}
echo "\n";

// 5. Probar controladores
echo "=== PRUEBA DE CONTROLADORES ===\n";

// CategoryController
try {
    $controller = new \App\Http\Controllers\Api\V1\CategoryController();
    $request = \Illuminate\Http\Request::create('/api/v1/categories', 'GET');
    $response = $controller->index();
    echo "✅ CategoryController::index() ejecutado\n";
    echo "   Status: " . $response->getStatusCode() . "\n";
} catch (\Exception $e) {
    echo "❌ CategoryController error: " . $e->getMessage() . "\n";
    echo "   Archivo: " . $e->getFile() . "\n";
    echo "   Línea: " . $e->getLine() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n";
}

// NudgeController
try {
    $controller = new \App\Http\Controllers\Api\V1\NudgeController();
    $request = \Illuminate\Http\Request::create('/api/v1/nudges/random', 'GET');
    $response = $controller->random();
    echo "✅ NudgeController::random() ejecutado\n";
    echo "   Status: " . $response->getStatusCode() . "\n";
} catch (\Exception $e) {
    echo "❌ NudgeController error: " . $e->getMessage() . "\n";
    echo "   Archivo: " . $e->getFile() . "\n";
    echo "   Línea: " . $e->getLine() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n";
}

// ExpertController
try {
    $controller = new \App\Http\Controllers\Api\V1\ExpertController();
    $request = \Illuminate\Http\Request::create('/api/v1/experts/nearby?lat=-37.6672&lng=-72.5730', 'GET');
    $response = $controller->nearby($request);
    echo "✅ ExpertController::nearby() ejecutado\n";
    echo "   Status: " . $response->getStatusCode() . "\n";
} catch (\Exception $e) {
    echo "❌ ExpertController error: " . $e->getMessage() . "\n";
    echo "   Archivo: " . $e->getFile() . "\n";
    echo "   Línea: " . $e->getLine() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n";

// 6. Verificar permisos
echo "=== PERMISOS ===\n";
$paths = [
    'storage/logs' => 'storage/logs',
    'storage/framework' => 'storage/framework',
    'bootstrap/cache' => 'bootstrap/cache',
];

foreach ($paths as $name => $path) {
    $fullPath = __DIR__ . '/' . $path;
    if (is_dir($fullPath)) {
        $writable = is_writable($fullPath) ? '✅' : '❌';
        echo "$writable $name: " . (is_writable($fullPath) ? 'escribible' : 'NO escribible') . "\n";
    } else {
        echo "❌ $name: NO existe\n";
    }
}

echo "\n";
echo "=== FIN DEL DIAGNÓSTICO ===\n";
echo "</pre>";

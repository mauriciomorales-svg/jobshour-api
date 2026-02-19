<?php
// Test directo de endpoints
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🧪 Probando endpoints directamente...\n\n";

// Test 1: Categories
echo "1. Probando /api/v1/categories\n";
try {
    $request = Illuminate\Http\Request::create('/api/v1/categories', 'GET');
    $response = $app->handle($request);
    echo "   Status: " . $response->getStatusCode() . "\n";
    echo "   Content: " . substr($response->getContent(), 0, 200) . "...\n";
} catch (\Exception $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n";

// Test 2: Experts Nearby
echo "2. Probando /api/v1/experts/nearby\n";
try {
    $request = Illuminate\Http\Request::create('/api/v1/experts/nearby?lat=-37.6672&lng=-72.5730&radius=10', 'GET');
    $response = $app->handle($request);
    echo "   Status: " . $response->getStatusCode() . "\n";
    $content = json_decode($response->getContent(), true);
    if (isset($content['data'])) {
        echo "   Expertos encontrados: " . count($content['data']) . "\n";
    } else {
        echo "   Response: " . substr($response->getContent(), 0, 200) . "...\n";
    }
} catch (\Exception $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Trace: " . substr($e->getTraceAsString(), 0, 500) . "...\n";
}

echo "\n";

// Test 3: Nudges
echo "3. Probando /api/v1/nudges/random\n";
try {
    $request = Illuminate\Http\Request::create('/api/v1/nudges/random', 'GET');
    $response = $app->handle($request);
    echo "   Status: " . $response->getStatusCode() . "\n";
    $content = json_decode($response->getContent(), true);
    if (isset($content['message'])) {
        echo "   Nudge: " . $content['message'] . "\n";
    } else {
        echo "   Response: " . substr($response->getContent(), 0, 200) . "...\n";
    }
} catch (\Exception $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n✅ Pruebas completadas\n";

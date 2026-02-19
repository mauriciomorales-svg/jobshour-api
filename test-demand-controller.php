<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

// Buscar un ServiceRequest de prueba
$request = App\Models\ServiceRequest::where('status', 'pending')
    ->whereNotNull('category_id')
    ->whereNotNull('client_id')
    ->first();

if (!$request) {
    echo "No se encontró ServiceRequest de prueba\n";
    exit(1);
}

echo "Testing ServiceRequest ID: {$request->id}\n";

// Cargar relaciones como lo hace el controller
$request->load(['client:id,name,avatar,phone', 'category:id,slug,display_name,color']);

echo "Category exists: " . ($request->category ? 'YES' : 'NO') . "\n";
echo "Client exists: " . ($request->client ? 'YES' : 'NO') . "\n";

if ($request->category) {
    echo "Category display_name: " . ($request->category->display_name ?? 'NULL') . "\n";
} else {
    echo "Category is NULL - this should be handled\n";
}

if ($request->client) {
    echo "Client name: " . ($request->client->name ?? 'NULL') . "\n";
} else {
    echo "Client is NULL - this should be handled\n";
}

// Simular lo que hace el controller
$categoryData = $request->category ? [
    'name' => $request->category->display_name ?? $request->category->name ?? 'Sin categoría',
    'color' => $request->category->color ?? '#6b7280',
] : [
    'name' => 'Sin categoría',
    'color' => '#6b7280',
];

$clientData = $request->client ? [
    'name' => $request->client->name,
    'avatar' => $request->client->avatar,
] : [
    'name' => 'Anónimo',
    'avatar' => null,
];

echo "\nCategory data: " . json_encode($categoryData) . "\n";
echo "Client data: " . json_encode($clientData) . "\n";

// Probar el controller real
try {
    $controller = new App\Http\Controllers\Api\V1\DemandMapController();
    $mockRequest = Illuminate\Http\Request::create("/api/v1/demand/{$request->id}", 'GET');
    $response = $controller->show($mockRequest, $request);
    
    echo "\n✅ Controller response status: " . $response->getStatusCode() . "\n";
    $data = json_decode($response->getContent(), true);
    echo "Response status: " . ($data['status'] ?? 'unknown') . "\n";
    
    if (isset($data['data']['category'])) {
        echo "Category in response: " . json_encode($data['data']['category']) . "\n";
    }
    if (isset($data['data']['client'])) {
        echo "Client in response: " . json_encode($data['data']['client']) . "\n";
    }
    
    echo "\n✅ TEST PASSED - No errors\n";
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit(1);
}

<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Probar con un ID real
$requestId = 7;
$request = Illuminate\Http\Request::create("/api/v1/demand/{$requestId}", 'GET');
$request->headers->set('Accept', 'application/json');

try {
    $response = $kernel->handle($request);
    echo "Status: " . $response->getStatusCode() . "\n";
    $data = json_decode($response->getContent(), true);
    
    if ($data && isset($data['status'])) {
        echo "Response status: " . $data['status'] . "\n";
        if (isset($data['data'])) {
            echo "Has data: YES\n";
            if (isset($data['data']['category'])) {
                echo "Category: " . json_encode($data['data']['category']) . "\n";
            } else {
                echo "Category: MISSING\n";
            }
            if (isset($data['data']['client'])) {
                echo "Client: " . json_encode($data['data']['client']) . "\n";
            } else {
                echo "Client: MISSING\n";
            }
        }
    } else {
        echo "Response: " . substr($response->getContent(), 0, 500) . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
$kernel->terminate($request, $response ?? null);

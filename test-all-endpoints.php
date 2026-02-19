<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$endpoints = [
    ['GET', '/', 'Root'],
    ['GET', '/api/v1/nudges/random', 'Nudges Random'],
    ['GET', '/api/v1/categories', 'Categories'],
    ['GET', '/api/v1/experts/nearby?lat=-37.6672&lng=-72.5730', 'Experts Nearby'],
    ['GET', '/api/v1/demand/nearby?lat=-37.6672&lng=-72.5730', 'Demand Nearby'],
    ['GET', '/api/v1/demand/7', 'Demand Show'],
    ['GET', '/api/v1/dashboard/feed?lat=-37.6672&lng=-72.5730&cursor=0', 'Dashboard Feed'],
    ['GET', '/api/v1/dashboard/live-stats?lat=-37.6672&lng=-72.5730&radius=50', 'Live Stats'],
    ['GET', '/api/v1/diagnostic/check', 'Diagnostic'],
];

$results = [];

foreach ($endpoints as $endpoint) {
    [$method, $path, $name] = $endpoint;
    
    try {
        $request = Illuminate\Http\Request::create($path, $method);
        $request->headers->set('Accept', 'application/json');
        
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $content = $response->getContent();
        
        $results[] = [
            'name' => $name,
            'path' => $path,
            'status' => $status,
            'success' => $status >= 200 && $status < 300,
            'error' => null,
            'content_preview' => substr($content, 0, 100)
        ];
        
        if ($status >= 400) {
            $results[count($results) - 1]['error'] = 'HTTP ' . $status;
        }
    } catch (Exception $e) {
        $results[] = [
            'name' => $name,
            'path' => $path,
            'status' => 500,
            'success' => false,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
    }
    
    $kernel->terminate($request, $response ?? null);
}

echo json_encode([
    'timestamp' => now()->toIso8601String(),
    'total_tested' => count($endpoints),
    'successful' => count(array_filter($results, fn($r) => $r['success'])),
    'failed' => count(array_filter($results, fn($r) => !$r['success'])),
    'results' => $results
], JSON_PRETTY_PRINT);

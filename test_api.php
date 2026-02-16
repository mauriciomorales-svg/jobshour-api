<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use Illuminate\Http\Request;

function test($method, $uri, $data = [], $token = null) {
    $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
    if ($token) $headers['Authorization'] = "Bearer $token";
    
    $request = Request::create($uri, $method, [], [], [], 
        array_merge(['HTTP_ACCEPT' => 'application/json'], $token ? ['HTTP_AUTHORIZATION' => "Bearer $token"] : []),
        json_encode($data)
    );
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Content-Type', 'application/json');
    if ($token) $request->headers->set('Authorization', "Bearer $token");

    $response = app()->handle($request);
    $status = $response->getStatusCode();
    $body = json_decode($response->getContent(), true);
    return [$status, $body];
}

echo "=== JOBSHOUR API TESTS ===\n\n";

// 1. Register Worker
echo "1. POST /api/auth/register (worker)\n";
[$s, $b] = test('POST', '/api/auth/register', [
    'name' => 'Carlos Gasfiter', 'email' => 'carlos@test.cl',
    'phone' => '+56911111111', 'password' => 'password123', 'type' => 'worker'
]);
$workerToken = $b['token'] ?? null;
echo "   Status: $s | Token: " . ($workerToken ? substr($workerToken, 0, 20).'...' : 'FAIL') . "\n";
echo "   User: " . ($b['user']['name'] ?? 'FAIL') . " | Type: " . ($b['user']['type'] ?? '?') . "\n";
echo "   Worker profile: " . (isset($b['user']['worker']['id']) ? 'CREADO (id='.$b['user']['worker']['id'].')' : 'FAIL') . "\n\n";
$workerId = $b['user']['worker']['id'] ?? null;

// 2. Register Employer
echo "2. POST /api/auth/register (employer)\n";
[$s, $b] = test('POST', '/api/auth/register', [
    'name' => 'Maria Empleadora', 'email' => 'maria@test.cl',
    'phone' => '+56922222222', 'password' => 'password123', 'type' => 'employer'
]);
$employerToken = $b['token'] ?? null;
echo "   Status: $s | Token: " . ($employerToken ? substr($employerToken, 0, 20).'...' : 'FAIL') . "\n\n";

// Verificar que las APIs respondan correctamente
$url = 'http://localhost:8002/api/v1/categories';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "API Categorias:\n";
echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . substr($response, 0, 500) . "\n";

// Verificar workers
$url2 = 'http://localhost:8002/api/v1/experts/nearby?lat=-37.6672&lng=-72.5730';
$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "\nAPI Experts Nearby:\n";
echo "HTTP Code: " . $httpCode2 . "\n";
echo "Response: " . substr($response2, 0, 500) . "\n";

// 3. Login
echo "3. POST /api/auth/login\n";
[$s, $b] = test('POST', '/api/auth/login', ['email' => 'carlos@test.cl', 'password' => 'password123']);
echo "   Status: $s | User: " . ($b['user']['name'] ?? 'FAIL') . "\n\n";

// 4. Login fail
echo "4. POST /api/auth/login (wrong password)\n";
[$s, $b] = test('POST', '/api/auth/login', ['email' => 'carlos@test.cl', 'password' => 'wrong']);
echo "   Status: $s | Error: " . ($b['message'] ?? 'none') . "\n\n";

// 5. Me
echo "5. GET /api/auth/me (con token)\n";
[$s, $b] = test('GET', '/api/auth/me', [], $workerToken);
echo "   Status: $s | Name: " . ($b['name'] ?? 'FAIL') . " | Email: " . ($b['email'] ?? '?') . "\n\n";

// 6. Me sin token
echo "6. GET /api/auth/me (sin token)\n";
[$s, $b] = test('GET', '/api/auth/me');
echo "   Status: $s | Message: " . ($b['message'] ?? '?') . "\n\n";

// 7. Workers list
echo "7. GET /api/workers\n";
[$s, $b] = test('GET', '/api/workers', [], $workerToken);
echo "   Status: $s | Total workers: " . (is_array($b['data'] ?? null) ? count($b['data']) : (is_array($b) ? count($b) : '?')) . "\n\n";

// 8. Worker show
if ($workerId) {
    echo "8. GET /api/workers/$workerId\n";
    [$s, $b] = test('GET', "/api/workers/$workerId", [], $workerToken);
    echo "   Status: $s | Title: " . ($b['title'] ?? $b['data']['title'] ?? 'null') . " | Availability: " . ($b['availability_status'] ?? $b['data']['availability_status'] ?? '?') . "\n\n";
}

// 9. Update availability
if ($workerId) {
    echo "9. POST /api/workers/$workerId/availability\n";
    [$s, $b] = test('POST', "/api/workers/$workerId/availability", ['availability_status' => 'available'], $workerToken);
    echo "   Status: $s | " . json_encode($b) . "\n\n";
}

// 10. Update location
if ($workerId) {
    echo "10. POST /api/workers/$workerId/location\n";
    [$s, $b] = test('POST', "/api/workers/$workerId/location", ['latitude' => -33.4489, 'longitude' => -70.6693, 'accuracy' => 10.5], $workerToken);
    echo "   Status: $s | " . json_encode($b) . "\n\n";
}

// 11. Map nearby workers
echo "11. GET /api/map/nearby-workers\n";
[$s, $b] = test('GET', '/api/map/nearby-workers?lat=-33.4489&lng=-70.6693&radius=50', [], $workerToken);
echo "   Status: $s | Results: " . (is_array($b['data'] ?? $b) ? count($b['data'] ?? $b) : '?') . "\n\n";

// 12. Create job
echo "12. POST /api/jobs (employer crea trabajo)\n";
[$s, $b] = test('POST', '/api/jobs', [
    'title' => 'Reparar cañería', 'description' => 'Cañería rota en baño',
    'skills_required' => ['gasfitería', 'plomería'], 'address' => 'Santiago Watt 205, Renaico',
    'budget' => 25000, 'payment_type' => 'fixed', 'urgency' => 'high',
    'latitude' => -33.4489, 'longitude' => -70.6693
], $employerToken);
echo "   Status: $s | " . ($b['title'] ?? $b['data']['title'] ?? $b['message'] ?? json_encode($b)) . "\n\n";

// 13. Jobs list
echo "13. GET /api/jobs\n";
[$s, $b] = test('GET', '/api/jobs', [], $workerToken);
echo "   Status: $s | Total jobs: " . (is_array($b['data'] ?? $b) ? count($b['data'] ?? $b) : '?') . "\n\n";

// 14. Logout
echo "14. POST /api/auth/logout\n";
[$s, $b] = test('POST', '/api/auth/logout', [], $workerToken);
echo "   Status: $s | " . ($b['message'] ?? 'FAIL') . "\n\n";

// 15. Verify token invalidated
echo "15. GET /api/auth/me (token invalidado)\n";
[$s, $b] = test('GET', '/api/auth/me', [], $workerToken);
echo "   Status: $s | " . ($b['message'] ?? '?') . "\n\n";

echo "=== TESTS COMPLETADOS ===\n";

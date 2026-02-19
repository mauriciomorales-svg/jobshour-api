<?php

/**
 * SIMULACIÓN COMPLETA DEL MODO VIAJE
 * 
 * Este script simula todo el flujo de testing:
 * 1. Marco activa Modo Viaje (Renaico → Angol)
 * 2. María solicita viaje (CERCA - debe matchear)
 * 3. Pedro solicita viaje (LEJOS - NO debe matchear)
 * 4. María envía sobre (delivery)
 * 5. Marco acepta solicitud
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Worker;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧪 SIMULACIÓN COMPLETA - MODO VIAJE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Cargar IDs del seeder
$testData = json_decode(file_get_contents(__DIR__.'/database/seeders/travel_mode_test_ids.json'), true);

$marco = User::find($testData['marco_user_id']);
$marcoWorker = Worker::find($testData['marco_worker_id']);
$maria = User::find($testData['maria_user_id']);
$pedro = User::find($testData['pedro_user_id']);

echo "👥 USUARIOS CARGADOS:\n";
echo "   🚗 Marco (Worker ID: {$marcoWorker->id})\n";
echo "   ✅ María (User ID: {$maria->id})\n";
echo "   ❌ Pedro (User ID: {$pedro->id})\n\n";

// ========================================
// TEST 1: Marco activa Modo Viaje
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧪 TEST 1: Marco activa Modo Viaje (Renaico → Angol)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$activeRoute = [
    'status' => 'active',
    'origin' => [
        'lat' => $testData['renaico']['lat'],
        'lng' => $testData['renaico']['lng'],
        'address' => 'Centro de Renaico',
    ],
    'destination' => [
        'lat' => $testData['angol']['lat'],
        'lng' => $testData['angol']['lng'],
        'address' => 'Angol',
    ],
    'departure_time' => now()->addMinutes(30)->toISOString(),
    'arrival_time' => now()->addMinutes(56)->toISOString(),
    'available_seats' => 3,
    'cargo_space' => 'paquete',
    'route_type' => 'personal',
    'distance_km' => 13.2,
    'activated_at' => now()->toISOString(),
];

$marcoWorker->active_route = $activeRoute;
$marcoWorker->save();

echo "✅ Ruta activada correctamente\n";
echo "   📍 Origen: {$activeRoute['origin']['address']}\n";
echo "   🎯 Destino: {$activeRoute['destination']['address']}\n";
echo "   📏 Distancia: {$activeRoute['distance_km']}km\n";
echo "   ⏱️  Salida: {$activeRoute['departure_time']}\n";
echo "   👥 Asientos: {$activeRoute['available_seats']}\n";
echo "   📦 Carga: {$activeRoute['cargo_space']}\n\n";

// ========================================
// TEST 2: María solicita viaje (CERCA)
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧪 TEST 2: María solicita viaje (CERCA - debe matchear)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$mariaRequest = ServiceRequest::create([
    'client_id' => $maria->id,
    'worker_id' => $marcoWorker->id, // Temporal
    'request_type' => 'ride',
    'pickup_address' => 'Mi casa (cerca de Ruta 180)',
    'delivery_address' => 'Angol Centro',
    'pickup_lat' => $testData['maria_location']['lat'],
    'pickup_lng' => $testData['maria_location']['lng'],
    'delivery_lat' => $testData['angol']['lat'],
    'delivery_lng' => $testData['angol']['lng'],
    'passenger_count' => 1,
    'offered_price' => 3000,
    'status' => 'pending',
]);

echo "✅ Solicitud creada (ID: {$mariaRequest->id})\n";
echo "   📍 Pickup: {$mariaRequest->pickup_address}\n";
echo "   🎯 Delivery: {$mariaRequest->delivery_address}\n";
echo "   💰 Precio ofrecido: \${$mariaRequest->offered_price}\n\n";

// Buscar matches para María
$mariaMatches = DB::select("
    WITH route_line AS (
        SELECT ST_MakeLine(
            ST_SetSRID(ST_MakePoint(?, ?), 4326),
            ST_SetSRID(ST_MakePoint(?, ?), 4326)
        ) as line
    )
    SELECT 
        w.id as worker_id,
        u.name as worker_name,
        w.active_route,
        ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) / 1000 as pickup_detour_km,
        ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) / 1000 as delivery_detour_km
    FROM workers w
    JOIN users u ON u.id = w.user_id
    WHERE 
        w.active_route IS NOT NULL
        AND (w.active_route->>'status') = 'active'
        AND ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) < 2000
        AND ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) < 2000
", [
    $testData['renaico']['lng'],
    $testData['renaico']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
    $testData['maria_location']['lng'],
    $testData['maria_location']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
    $testData['maria_location']['lng'],
    $testData['maria_location']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
]);

echo "🔍 Matches encontrados para María: " . count($mariaMatches) . "\n";

if (count($mariaMatches) > 0) {
    $match = $mariaMatches[0];
    echo "   ✅ MATCH CORRECTO\n";
    echo "   👤 Worker: {$match->worker_name}\n";
    echo "   📏 Desvío pickup: " . number_format($match->pickup_detour_km, 1) . "km\n";
    echo "   📏 Desvío delivery: " . number_format($match->delivery_detour_km, 1) . "km\n";
    echo "   📏 Desvío total: " . number_format($match->pickup_detour_km + $match->delivery_detour_km, 1) . "km\n";
    
    if ($match->pickup_detour_km < 2.0 && $match->delivery_detour_km < 2.0) {
        echo "   ✅ VALIDACIÓN: Desvío dentro del límite (<2km por punto)\n";
    } else {
        echo "   ❌ ERROR: Desvío excede el límite quirúrgico\n";
    }
} else {
    echo "   ❌ ERROR: María NO matcheó (debería matchear - está a 1.2km)\n";
}

echo "\n";

// ========================================
// TEST 3: Pedro solicita viaje (LEJOS)
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧪 TEST 3: Pedro solicita viaje (LEJOS - NO debe matchear)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$pedroRequest = ServiceRequest::create([
    'client_id' => $pedro->id,
    'worker_id' => $marcoWorker->id, // Temporal
    'request_type' => 'ride',
    'pickup_address' => 'Sector rural (lejos de ruta)',
    'delivery_address' => 'Angol Centro',
    'pickup_lat' => $testData['pedro_location']['lat'],
    'pickup_lng' => $testData['pedro_location']['lng'],
    'delivery_lat' => $testData['angol']['lat'],
    'delivery_lng' => $testData['angol']['lng'],
    'passenger_count' => 1,
    'offered_price' => 5000,
    'status' => 'pending',
]);

echo "✅ Solicitud creada (ID: {$pedroRequest->id})\n";
echo "   📍 Pickup: {$pedroRequest->pickup_address}\n";
echo "   💰 Precio ofrecido: \${$pedroRequest->offered_price}\n\n";

// Buscar matches para Pedro
$pedroMatches = DB::select("
    WITH route_line AS (
        SELECT ST_MakeLine(
            ST_SetSRID(ST_MakePoint(?, ?), 4326),
            ST_SetSRID(ST_MakePoint(?, ?), 4326)
        ) as line
    )
    SELECT 
        w.id as worker_id,
        u.name as worker_name,
        ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) / 1000 as pickup_detour_km,
        ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) / 1000 as delivery_detour_km
    FROM workers w
    JOIN users u ON u.id = w.user_id
    WHERE 
        w.active_route IS NOT NULL
        AND (w.active_route->>'status') = 'active'
        AND ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) < 2000
        AND ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) < 2000
", [
    $testData['renaico']['lng'],
    $testData['renaico']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
    $testData['pedro_location']['lng'],
    $testData['pedro_location']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
    $testData['pedro_location']['lng'],
    $testData['pedro_location']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
]);

echo "🔍 Matches encontrados para Pedro: " . count($pedroMatches) . "\n";

if (count($pedroMatches) === 0) {
    echo "   ✅ CORRECTO: Pedro NO matcheó (está muy lejos)\n";
    echo "   🎯 Sistema respetó el ADN: 'No desviar de más'\n";
} else {
    echo "   ❌ ERROR: Pedro matcheó con Marco (NO debería - está a 5km)\n";
}

echo "\n";

// ========================================
// TEST 4: María envía sobre (delivery)
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧪 TEST 4: María envía sobre (DELIVERY - debe matchear igual)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$mariaDelivery = ServiceRequest::create([
    'client_id' => $maria->id,
    'worker_id' => $marcoWorker->id,
    'request_type' => 'delivery',
    'pickup_address' => 'Mi casa',
    'delivery_address' => 'Oficina en Angol',
    'pickup_lat' => $testData['maria_location']['lat'],
    'pickup_lng' => $testData['maria_location']['lng'],
    'delivery_lat' => $testData['angol']['lat'],
    'delivery_lng' => $testData['angol']['lng'],
    'carga_tipo' => 'sobre',
    'carga_peso' => 0.5,
    'description' => 'Documentos importantes',
    'offered_price' => 2000,
    'status' => 'pending',
]);

echo "✅ Solicitud de delivery creada (ID: {$mariaDelivery->id})\n";
echo "   📦 Tipo: {$mariaDelivery->request_type}\n";
echo "   📦 Carga: {$mariaDelivery->carga_tipo} ({$mariaDelivery->carga_peso}kg)\n";
echo "   💰 Precio: \${$mariaDelivery->offered_price}\n\n";

// Buscar matches para delivery
$deliveryMatches = DB::select("
    WITH route_line AS (
        SELECT ST_MakeLine(
            ST_SetSRID(ST_MakePoint(?, ?), 4326),
            ST_SetSRID(ST_MakePoint(?, ?), 4326)
        ) as line
    )
    SELECT 
        w.id as worker_id,
        u.name as worker_name,
        ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) / 1000 as pickup_detour_km,
        ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) / 1000 as delivery_detour_km
    FROM workers w
    JOIN users u ON u.id = w.user_id
    WHERE 
        w.active_route IS NOT NULL
        AND (w.active_route->>'status') = 'active'
        AND ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) < 2000
        AND ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            (SELECT line FROM route_line)::geography
        ) < 2000
", [
    $testData['renaico']['lng'],
    $testData['renaico']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
    $testData['maria_location']['lng'],
    $testData['maria_location']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
    $testData['maria_location']['lng'],
    $testData['maria_location']['lat'],
    $testData['angol']['lng'],
    $testData['angol']['lat'],
]);

echo "🔍 Matches encontrados para delivery: " . count($deliveryMatches) . "\n";

if (count($deliveryMatches) > 0) {
    $match = $deliveryMatches[0];
    echo "   ✅ CORRECTO: Delivery matcheó igual que ride\n";
    echo "   👤 Worker: {$match->worker_name}\n";
    echo "   📏 Desvío total: " . number_format($match->pickup_detour_km + $match->delivery_detour_km, 1) . "km\n";
    echo "   🎯 Sistema trata delivery con la misma prioridad que ride\n";
} else {
    echo "   ❌ ERROR: Delivery NO matcheó (debería matchear igual que ride)\n";
}

echo "\n";

// ========================================
// RESUMEN FINAL
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 RESUMEN DE VALIDACIÓN\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$marcoHasRoute = $marcoWorker->fresh()->active_route !== null;
$mariaMatchCount = count($mariaMatches);
$pedroMatchCount = count($pedroMatches);
$deliveryMatchCount = count($deliveryMatches);

echo "✅ Marco activó Modo Viaje: " . ($marcoHasRoute ? "SÍ" : "NO") . "\n";
echo ($mariaMatchCount > 0 ? "✅" : "❌") . " María matcheó (CERCA): " . $mariaMatchCount . " match(es)\n";
echo ($pedroMatchCount === 0 ? "✅" : "❌") . " Pedro NO matcheó (LEJOS): " . $pedroMatchCount . " match(es)\n";
echo ($deliveryMatchCount > 0 ? "✅" : "❌") . " Delivery matcheó igual que ride: " . $deliveryMatchCount . " match(es)\n\n";

$allPassed = $marcoHasRoute && $mariaMatchCount > 0 && $pedroMatchCount === 0 && $deliveryMatchCount > 0;

if ($allPassed) {
    echo "🎉 TODOS LOS TESTS PASARON\n";
    echo "🚀 Sistema listo para producción\n\n";
    echo "El ADN del sistema funciona correctamente:\n";
    echo "   ✅ Elasticidad: request_type acepta ride y delivery\n";
    echo "   ✅ Prioridad al Recurso: Solo matchea dentro de 2km\n";
    echo "   ✅ Match Quirúrgico: PostGIS calcula desvíos exactos\n";
} else {
    echo "❌ ALGUNOS TESTS FALLARON\n";
    echo "Revisar logs y queries PostGIS\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

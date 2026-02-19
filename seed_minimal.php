<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Worker;
use App\Models\ServiceRequest;

echo "🌍 Creando sistema mínimo para pruebas Renaico-Angol...\n\n";

// Coordenadas Renaico: -37.6672, -72.5730
// Coordenadas Angol: -37.8000, -72.7000

// ===== CREAR 3 WORKERS =====
$workers = [];

// Worker 1 - Renaico Centro
$user1 = User::create([
    'name' => 'Juan Pérez',
    'email' => 'juan.renaico@test.com',
    'password' => bcrypt('password'),
    'phone' => '+56912340001',
    'lat' => -37.6672,
    'lng' => -72.5730,
    'nickname' => 'ElMaestro',
]);
$worker1 = Worker::create([
    'user_id' => $user1->id,
    'availability_status' => 'active',
    'hourly_rate' => 8000,
]);
$workers[] = $worker1;
echo "✓ Worker 1 creado: Juan Pérez (Renaico Centro) - VERDE\n";

// Worker 2 - Angol
$user2 = User::create([
    'name' => 'María González',
    'email' => 'maria.angol@test.com',
    'password' => bcrypt('password'),
    'phone' => '+56912340002',
    'lat' => -37.8000,
    'lng' => -72.7000,
    'nickname' => 'LaChispa',
]);
$worker2 = Worker::create([
    'user_id' => $user2->id,
    'availability_status' => 'intermediate',
    'hourly_rate' => 7000,
]);
$workers[] = $worker2;
echo "✓ Worker 2 creado: María González (Angol) - AMARILLO\n";

// Worker 3 - Entre Renaico y Angol
$user3 = User::create([
    'name' => 'Pedro López',
    'email' => 'pedro.medio@test.com',
    'password' => bcrypt('password'),
    'phone' => '+56912340003',
    'lat' => -37.7300,
    'lng' => -72.6400,
    'nickname' => 'ElPintor',
]);
$worker3 = Worker::create([
    'user_id' => $user3->id,
    'availability_status' => 'inactive',
    'hourly_rate' => 9000,
]);
$workers[] = $worker3;
echo "✓ Worker 3 creado: Pedro López (Entre Renaico-Angol) - ROJO\n";

// ===== CREAR 3 SOLICITUDES DORADAS =====
echo "\n💰 Creando 3 solicitudes doradas...\n";

// Cliente dummy
$client = User::create([
    'name' => 'Cliente Test',
    'email' => 'cliente.test@test.com',
    'password' => bcrypt('password'),
    'phone' => '+56912340000',
]);

// Solicitud 1 - Viaje urgente (Renaico)
$req1 = ServiceRequest::create([
    'client_id' => $client->id,
    'worker_id' => $worker1->id,
    'description' => 'Viaje Urgente Renaico → Angol',
    'type' => 'ride_share',
    'status' => 'pending',
    'urgency' => 'urgent',
    'offered_price' => 5000,
    'payload' => json_encode([
        'seats' => 2,
        'departure_time' => '2026-02-18 08:00:00',
        'destination_name' => 'Angol',
        'vehicle_type' => 'Auto',
    ]),
    'expires_at' => now()->addHours(2),
    'pin_expires_at' => now()->addHours(24),
]);
DB::statement("UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?", [-72.5730, -37.6672, $req1->id]);
echo "✓ Solicitud 1: Viaje Urgente (🔥 $5k) - Renaico\n";

// Solicitud 2 - Compra normal (Angol)
$req2 = ServiceRequest::create([
    'client_id' => $client->id,
    'worker_id' => $worker2->id,
    'description' => 'Compra Supermercado Angol',
    'type' => 'express_errand',
    'status' => 'pending',
    'urgency' => 'normal',
    'offered_price' => 8000,
    'payload' => json_encode([
        'store_name' => 'Supermercado Angol',
        'items_count' => 15,
        'load_type' => 'medium',
        'requires_vehicle' => true,
    ]),
    'expires_at' => now()->addHours(6),
    'pin_expires_at' => now()->addHours(24),
]);
DB::statement("UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?", [-72.7000, -37.8000, $req2->id]);
echo "✓ Solicitud 2: Compra Supermercado ($8k) - Angol\n";

// Solicitud 3 - Reparación urgente (Entre ambos)
$req3 = ServiceRequest::create([
    'client_id' => $client->id,
    'worker_id' => $worker3->id,
    'description' => 'Reparación Gasfitería Urgente',
    'type' => 'fixed_job',
    'status' => 'pending',
    'urgency' => 'urgent',
    'offered_price' => 25000,
    'payload' => json_encode([
        'category' => 'Gasfitería',
        'urgency' => 'urgent',
        'tools_provided' => false,
        'estimated_hours' => 2,
    ]),
    'expires_at' => now()->addHours(4),
    'pin_expires_at' => now()->addHours(24),
]);
DB::statement("UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?", [-72.6400, -37.7300, $req3->id]);
echo "✓ Solicitud 3: Reparación Gasfitería (🔥 $25k) - Entre Renaico-Angol\n";

echo "\n✅ SISTEMA LISTO PARA PRUEBAS\n";
echo "📍 3 Workers: Verde (Renaico), Amarillo (Angol), Rojo (Medio)\n";
echo "💰 3 Solicitudes doradas: 2 urgentes (🔥), 1 normal\n";
echo "🗺️  Zona: Renaico-Angol\n";

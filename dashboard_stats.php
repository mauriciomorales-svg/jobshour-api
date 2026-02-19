<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Worker;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 DASHBOARD JOBSHOUR - " . now()->format('d/m/Y H:i') . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// USUARIOS
echo "👥 USUARIOS:\n";
$totalUsers = User::count();
$usersToday = User::whereDate('created_at', today())->count();
echo "   Total: {$totalUsers}\n";
echo "   Registrados hoy: {$usersToday}\n\n";

// WORKERS
echo "🚗 WORKERS:\n";
$totalWorkers = Worker::count();
$activeWorkers = Worker::where('availability_status', 'active')->count();
$intermediateWorkers = Worker::where('availability_status', 'intermediate')->count();
$inactiveWorkers = Worker::where('availability_status', 'inactive')->count();
$workersWithRoute = Worker::whereNotNull('active_route')->count();

echo "   Total: {$totalWorkers}\n";
echo "   🟢 Activos: {$activeWorkers}\n";
echo "   🟡 Intermediate: {$intermediateWorkers}\n";
echo "   ⚪ Inactivos: {$inactiveWorkers}\n";
echo "   🚗 Con Modo Viaje activo: {$workersWithRoute}\n\n";

// SOLICITUDES
echo "📋 SOLICITUDES:\n";
$totalRequests = ServiceRequest::count();
$pendingRequests = ServiceRequest::where('status', 'pending')->count();
$acceptedRequests = ServiceRequest::where('status', 'accepted')->count();
$completedRequests = ServiceRequest::where('status', 'completed')->count();
$rideRequests = ServiceRequest::where('request_type', 'ride')->count();
$deliveryRequests = ServiceRequest::where('request_type', 'delivery')->count();
$requestsToday = ServiceRequest::whereDate('created_at', today())->count();

echo "   Total: {$totalRequests}\n";
echo "   ⏳ Pendientes: {$pendingRequests}\n";
echo "   ✅ Aceptadas: {$acceptedRequests}\n";
echo "   🎉 Completadas: {$completedRequests}\n";
echo "   🚗 Viajes (ride): {$rideRequests}\n";
echo "   📦 Envíos (delivery): {$deliveryRequests}\n";
echo "   📅 Creadas hoy: {$requestsToday}\n\n";

// MODO VIAJE (si hay rutas activas)
if ($workersWithRoute > 0) {
    echo "🚗 MODO VIAJE ACTIVO:\n";
    $activeRoutes = Worker::whereNotNull('active_route')
        ->with('user')
        ->get();
    
    foreach ($activeRoutes as $worker) {
        $route = is_string($worker->active_route) 
            ? json_decode($worker->active_route, true) 
            : $worker->active_route;
        
        echo "   👤 {$worker->user->name}\n";
        echo "      📍 {$route['origin']['address']} → {$route['destination']['address']}\n";
        echo "      📏 {$route['distance_km']}km\n";
        echo "      👥 {$route['available_seats']} asientos\n";
        if (isset($route['cargo_space'])) {
            echo "      📦 Carga: {$route['cargo_space']}\n";
        }
        echo "\n";
    }
}

// ÚLTIMAS SOLICITUDES
echo "📋 ÚLTIMAS 5 SOLICITUDES:\n";
$recentRequests = ServiceRequest::with('client')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach ($recentRequests as $req) {
    $clientName = $req->client ? $req->client->name : 'Cliente desconocido';
    $type = $req->request_type ?: 'service';
    $status = $req->status;
    $created = $req->created_at->diffForHumans();
    
    echo "   • [{$type}] {$clientName} - {$status} ({$created})\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

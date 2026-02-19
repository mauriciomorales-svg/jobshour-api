<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
use App\Models\Worker;
use App\Models\ServiceRequest;

/**
 * TESTING COMPLETO - MODO VIAJE
 * 
 * Validación del ADN: "Prioridad al Recurso - No desviar de más"
 * 
 * Escenario:
 * - Marco (Worker): Renaico → Angol
 * - María (Cliente A): 1.2km de la ruta → DEBE MATCHEAR ✅
 * - Pedro (Cliente B): 5km de la ruta → NO DEBE MATCHEAR ❌
 */
class TravelModeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected $marco;
    protected $marcoWorker;
    protected $maria;
    protected $pedro;
    protected $testData;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ejecutar seeder de testing
        Artisan::call('db:seed', ['--class' => 'TravelModeTestSeeder']);
        
        // Cargar IDs del seeder
        $this->testData = json_decode(
            file_get_contents(database_path('seeders/travel_mode_test_ids.json')),
            true
        );
        
        $this->marco = User::find($this->testData['marco_user_id']);
        $this->marcoWorker = Worker::find($this->testData['marco_worker_id']);
        $this->maria = User::find($this->testData['maria_user_id']);
        $this->pedro = User::find($this->testData['pedro_user_id']);
    }

    /** @test */
    public function test_1_marco_activa_modo_viaje_renaico_angol()
    {
        echo "\n🧪 TEST 1: Marco activa Modo Viaje (Renaico → Angol)\n";
        
        $response = $this->actingAs($this->marco)
            ->postJson('/api/v1/worker/travel-mode/activate', [
                'origin_lat' => $this->testData['renaico']['lat'],
                'origin_lng' => $this->testData['renaico']['lng'],
                'origin_address' => 'Centro de Renaico',
                'destination_lat' => $this->testData['angol']['lat'],
                'destination_lng' => $this->testData['angol']['lng'],
                'destination_address' => 'Angol',
                'departure_time' => now()->addMinutes(30)->toISOString(),
                'available_seats' => 3,
                'cargo_space' => 'paquete',
                'route_type' => 'personal',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'active_route',
                'potential_matches',
                'matches',
            ],
        ]);

        $data = $response->json('data');
        
        echo "   ✅ Ruta activada correctamente\n";
        echo "   📍 Origen: {$data['active_route']['origin']['address']}\n";
        echo "   🎯 Destino: {$data['active_route']['destination']['address']}\n";
        echo "   📏 Distancia: {$data['active_route']['distance_km']}km\n";
        echo "   ⏱️  Salida: {$data['active_route']['departure_time']}\n";
        echo "   🔍 Matches potenciales: {$data['potential_matches']}\n\n";

        $this->assertEquals('active', $data['active_route']['status']);
        $this->assertEquals(3, $data['active_route']['available_seats']);
    }

    /** @test */
    public function test_2_maria_solicita_viaje_debe_matchear()
    {
        echo "\n🧪 TEST 2: María solicita viaje (CERCA - debe matchear)\n";
        
        // Primero Marco activa su ruta
        $this->actingAs($this->marco)
            ->postJson('/api/v1/worker/travel-mode/activate', [
                'origin_lat' => $this->testData['renaico']['lat'],
                'origin_lng' => $this->testData['renaico']['lng'],
                'origin_address' => 'Centro de Renaico',
                'destination_lat' => $this->testData['angol']['lat'],
                'destination_lng' => $this->testData['angol']['lng'],
                'destination_address' => 'Angol',
                'departure_time' => now()->addMinutes(30)->toISOString(),
                'available_seats' => 3,
            ]);

        // María solicita viaje
        $response = $this->actingAs($this->maria)
            ->postJson('/api/v1/travel-requests', [
                'request_type' => 'ride',
                'pickup_lat' => $this->testData['maria_location']['lat'],
                'pickup_lng' => $this->testData['maria_location']['lng'],
                'pickup_address' => 'Mi casa (cerca de Ruta 180)',
                'delivery_lat' => $this->testData['angol']['lat'],
                'delivery_lng' => $this->testData['angol']['lng'],
                'delivery_address' => 'Angol Centro',
                'passenger_count' => 1,
                'offered_price' => 3000,
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        echo "   ✅ Solicitud creada\n";
        echo "   🔍 Matches encontrados: {$data['matches_found']}\n";

        $this->assertGreaterThan(0, $data['matches_found'], 
            '❌ ERROR: María NO matcheó con Marco (debería matchear - está a 1.2km)');

        if ($data['matches_found'] > 0) {
            $match = $data['matches'][0];
            echo "   👤 Worker: {$match->worker_name}\n";
            echo "   📏 Desvío pickup: " . number_format($match->pickup_detour_km, 1) . "km\n";
            echo "   📏 Desvío delivery: " . number_format($match->delivery_detour_km, 1) . "km\n";
            echo "   📏 Desvío total: " . number_format($match->total_detour_km, 1) . "km\n";
            
            $this->assertLessThan(2.0, $match->pickup_detour_km,
                '❌ ERROR: Desvío de pickup > 2km (filtro quirúrgico falló)');
            $this->assertLessThan(2.0, $match->delivery_detour_km,
                '❌ ERROR: Desvío de delivery > 2km (filtro quirúrgico falló)');
            
            echo "   ✅ MATCH CORRECTO: Desvío dentro del límite (<2km por punto)\n\n";
        }
    }

    /** @test */
    public function test_3_pedro_solicita_viaje_no_debe_matchear()
    {
        echo "\n🧪 TEST 3: Pedro solicita viaje (LEJOS - NO debe matchear)\n";
        
        // Marco activa su ruta
        $this->actingAs($this->marco)
            ->postJson('/api/v1/worker/travel-mode/activate', [
                'origin_lat' => $this->testData['renaico']['lat'],
                'origin_lng' => $this->testData['renaico']['lng'],
                'origin_address' => 'Centro de Renaico',
                'destination_lat' => $this->testData['angol']['lat'],
                'destination_lng' => $this->testData['angol']['lng'],
                'destination_address' => 'Angol',
                'departure_time' => now()->addMinutes(30)->toISOString(),
                'available_seats' => 3,
            ]);

        // Pedro solicita viaje (está a 5km)
        $response = $this->actingAs($this->pedro)
            ->postJson('/api/v1/travel-requests', [
                'request_type' => 'ride',
                'pickup_lat' => $this->testData['pedro_location']['lat'],
                'pickup_lng' => $this->testData['pedro_location']['lng'],
                'pickup_address' => 'Sector rural (lejos de ruta)',
                'delivery_lat' => $this->testData['angol']['lat'],
                'delivery_lng' => $this->testData['angol']['lng'],
                'delivery_address' => 'Angol Centro',
                'passenger_count' => 1,
                'offered_price' => 5000,
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        echo "   ✅ Solicitud creada\n";
        echo "   🔍 Matches encontrados: {$data['matches_found']}\n";

        $this->assertEquals(0, $data['matches_found'],
            '❌ ERROR: Pedro matcheó con Marco (NO debería - está a 5km)');

        echo "   ✅ CORRECTO: Pedro NO matcheó (está muy lejos)\n";
        echo "   🎯 Sistema respetó el ADN: 'No desviar de más'\n\n";
    }

    /** @test */
    public function test_4_maria_envia_sobre_delivery()
    {
        echo "\n🧪 TEST 4: María envía sobre (DELIVERY - debe matchear igual)\n";
        
        // Marco activa su ruta
        $this->actingAs($this->marco)
            ->postJson('/api/v1/worker/travel-mode/activate', [
                'origin_lat' => $this->testData['renaico']['lat'],
                'origin_lng' => $this->testData['renaico']['lng'],
                'origin_address' => 'Centro de Renaico',
                'destination_lat' => $this->testData['angol']['lat'],
                'destination_lng' => $this->testData['angol']['lng'],
                'destination_address' => 'Angol',
                'departure_time' => now()->addMinutes(30)->toISOString(),
                'cargo_space' => 'sobre',
            ]);

        // María envía sobre
        $response = $this->actingAs($this->maria)
            ->postJson('/api/v1/travel-requests', [
                'request_type' => 'delivery',
                'pickup_lat' => $this->testData['maria_location']['lat'],
                'pickup_lng' => $this->testData['maria_location']['lng'],
                'pickup_address' => 'Mi casa',
                'delivery_lat' => $this->testData['angol']['lat'],
                'delivery_lng' => $this->testData['angol']['lng'],
                'delivery_address' => 'Oficina en Angol',
                'carga_tipo' => 'sobre',
                'carga_peso' => 0.5,
                'description' => 'Documentos importantes',
                'offered_price' => 2000,
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        echo "   ✅ Solicitud de delivery creada\n";
        echo "   📦 Tipo: {$data['request_type']}\n";
        echo "   🔍 Matches encontrados: {$data['matches_found']}\n";

        $this->assertGreaterThan(0, $data['matches_found'],
            '❌ ERROR: Delivery NO matcheó (debería matchear igual que ride)');

        if ($data['matches_found'] > 0) {
            $match = $data['matches'][0];
            echo "   👤 Worker: {$match->worker_name}\n";
            echo "   📏 Desvío total: " . number_format($match->total_detour_km, 1) . "km\n";
            echo "   ✅ CORRECTO: Sistema trata delivery con misma prioridad que ride\n\n";
        }
    }

    /** @test */
    public function test_5_marco_acepta_solicitud_de_maria()
    {
        echo "\n🧪 TEST 5: Marco acepta solicitud de María\n";
        
        // Marco activa ruta
        $this->actingAs($this->marco)
            ->postJson('/api/v1/worker/travel-mode/activate', [
                'origin_lat' => $this->testData['renaico']['lat'],
                'origin_lng' => $this->testData['renaico']['lng'],
                'origin_address' => 'Centro de Renaico',
                'destination_lat' => $this->testData['angol']['lat'],
                'destination_lng' => $this->testData['angol']['lng'],
                'destination_address' => 'Angol',
                'departure_time' => now()->addMinutes(30)->toISOString(),
                'available_seats' => 3,
            ]);

        // María solicita viaje
        $requestResponse = $this->actingAs($this->maria)
            ->postJson('/api/v1/travel-requests', [
                'request_type' => 'ride',
                'pickup_lat' => $this->testData['maria_location']['lat'],
                'pickup_lng' => $this->testData['maria_location']['lng'],
                'pickup_address' => 'Mi casa',
                'delivery_lat' => $this->testData['angol']['lat'],
                'delivery_lng' => $this->testData['angol']['lng'],
                'delivery_address' => 'Angol Centro',
                'passenger_count' => 1,
                'offered_price' => 3000,
            ]);

        $requestId = $requestResponse->json('data.request_id');

        // Marco acepta
        $response = $this->actingAs($this->marco)
            ->postJson("/api/v1/travel-requests/{$requestId}/accept");

        $response->assertStatus(200);
        $data = $response->json('data');

        echo "   ✅ Solicitud aceptada\n";
        echo "   📍 Pickup: {$data['pickup_address']}\n";
        echo "   🎯 Delivery: {$data['delivery_address']}\n";
        echo "   👤 Cliente: {$data['request']['client']['name']}\n";
        
        // Verificar que el status cambió a 'accepted'
        $request = ServiceRequest::find($requestId);
        $this->assertEquals('accepted', $request->status);
        $this->assertEquals($this->marcoWorker->id, $request->worker_id);
        
        echo "   ✅ Status actualizado a 'accepted'\n";
        echo "   ✅ Worker asignado correctamente\n\n";
    }

    /** @test */
    public function test_6_performance_match_debe_ser_rapido()
    {
        echo "\n🧪 TEST 6: Performance - Match debe ser instantáneo (<1s)\n";
        
        // Marco activa ruta
        $this->actingAs($this->marco)
            ->postJson('/api/v1/worker/travel-mode/activate', [
                'origin_lat' => $this->testData['renaico']['lat'],
                'origin_lng' => $this->testData['renaico']['lng'],
                'origin_address' => 'Centro de Renaico',
                'destination_lat' => $this->testData['angol']['lat'],
                'destination_lng' => $this->testData['angol']['lng'],
                'destination_address' => 'Angol',
                'departure_time' => now()->addMinutes(30)->toISOString(),
                'available_seats' => 3,
            ]);

        // Medir tiempo de match
        $startTime = microtime(true);
        
        $response = $this->actingAs($this->maria)
            ->postJson('/api/v1/travel-requests', [
                'request_type' => 'ride',
                'pickup_lat' => $this->testData['maria_location']['lat'],
                'pickup_lng' => $this->testData['maria_location']['lng'],
                'pickup_address' => 'Mi casa',
                'delivery_lat' => $this->testData['angol']['lat'],
                'delivery_lng' => $this->testData['angol']['lng'],
                'delivery_address' => 'Angol Centro',
                'passenger_count' => 1,
            ]);

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // ms

        echo "   ⏱️  Tiempo de match: " . number_format($duration, 2) . "ms\n";

        $this->assertLessThan(1000, $duration,
            '❌ ERROR: Match tardó más de 1 segundo (sistema no escalará)');

        echo "   ✅ PERFORMANCE CORRECTA: Match instantáneo\n";
        echo "   🚀 Sistema listo para escalar\n\n";
    }
}

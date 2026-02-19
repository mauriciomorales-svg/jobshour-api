<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Worker;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

class ServiceRequestSeeder extends Seeder
{
    public function run(): void
    {
        // Coordenadas base: Los Ángeles, Chile (-37.4689, -72.3527)
        // Radio: 10km para distribución geográfica
        
        // Obtener workers existentes para asignarles solicitudes
        $workers = Worker::with('user')->limit(30)->get();
        
        if ($workers->count() < 10) {
            $this->command->error('⚠️  Se necesitan al menos 10 workers en la BD. Ejecuta WorkerSeeder primero.');
            return;
        }
        
        // Obtener o crear clientes para las solicitudes
        $clients = collect();
        for ($i = 1; $i <= 30; $i++) {
            $email = "cliente.demo.$i@jobshour.test";
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => "Cliente Demo $i",
                    'password' => bcrypt('password'),
                    'phone' => "+569" . str_pad(80000000 + $i, 8, '0', STR_PAD_LEFT),
                ]
            );
            $clients->push($user);
        }

        $categories = Category::all()->keyBy('slug');

        $requests = [
            // ═══════════════════════════════════════════════════════════
            // 6 SOLICITUDES DE PRUEBA
            // ═══════════════════════════════════════════════════════════
            [
                'client_id' => $clients[0]->id,
                'worker_id' => $workers[0]->id,
                'description' => 'Viaje Los Ángeles → Concepción',
                'type' => 'ride_share',
                'status' => 'pending',
                'urgency' => 'urgent',
                'offered_price' => 6000,
                'lat' => -37.4689,
                'lng' => -72.3527,
                'payload' => [
                    'seats' => 3,
                    'departure_time' => '2026-02-18 08:30:00',
                    'destination_name' => 'Concepción',
                    'vehicle_type' => 'Sedán',
                ],
                'expires_at' => now()->addHours(6),
                'pin_expires_at' => now()->addHours(24),
            ],
            [
                'client_id' => $clients[1]->id,
                'worker_id' => $workers[1]->id,
                'description' => 'Compra Supermercado Jumbo',
                'type' => 'express_errand',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 5000,
                'lat' => -37.4750,
                'lng' => -72.3600,
                'payload' => [
                    'store_name' => 'Jumbo',
                    'items_count' => 15,
                    'load_type' => 'medium',
                    'requires_vehicle' => true,
                ],
                'expires_at' => now()->addHours(4),
                'pin_expires_at' => now()->addHours(24),
            ],
            [
                'client_id' => $clients[2]->id,
                'worker_id' => $workers[2]->id,
                'description' => 'Reparación Gasfitería Urgente',
                'type' => 'fixed_job',
                'status' => 'pending',
                'urgency' => 'urgent',
                'offered_price' => 25000,
                'lat' => -37.4620,
                'lng' => -72.3450,
                'payload' => [
                    'category' => 'Gasfitería',
                    'urgency' => 'urgent',
                    'tools_provided' => false,
                    'estimated_hours' => 2,
                ],
                'expires_at' => now()->addHours(2),
                'pin_expires_at' => now()->addHours(24),
            ],
            [
                'client_id' => $clients[3]->id,
                'worker_id' => $workers[3]->id,
                'description' => 'Transporte Renaico Centro',
                'type' => 'ride_share',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 2000,
                'lat' => -37.6672,
                'lng' => -72.5730,
                'payload' => [
                    'seats' => 2,
                    'departure_time' => '2026-02-18 15:00:00',
                    'destination_name' => 'Centro Renaico',
                    'vehicle_type' => 'Pickup',
                ],
                'expires_at' => now()->addHours(6),
                'pin_expires_at' => now()->addHours(24),
            ],
            [
                'client_id' => $clients[4]->id,
                'worker_id' => $workers[4]->id,
                'description' => 'Pintura Exterior Casa',
                'type' => 'fixed_job',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 15000,
                'lat' => -37.4650,
                'lng' => -72.3500,
                'payload' => [
                    'store_name' => 'Jumbo',
                    'items_count' => 35,
                    'load_type' => 'heavy',
                    'requires_vehicle' => true,
                ],
                'expires_at' => now()->addHours(5),
                'pin_expires_at' => now()->addHours(24),
            ],
            [
                'client_id' => $clients[5]->id,
                'worker_id' => $workers[5]->id,
                'description' => 'Compra Farmacia Cruz Verde',
                'type' => 'express_errand',
                'status' => 'pending',
                'urgency' => 'urgent',
                'offered_price' => 3000,
                'lat' => -37.4650,
                'lng' => -72.3480,
                'payload' => [
                    'store_name' => 'Farmacia Cruz Verde',
                    'items_count' => 3,
                    'load_type' => 'light',
                    'requires_vehicle' => false,
                ],
                'expires_at' => now()->addHours(1),
                'pin_expires_at' => now()->addHours(24),
            ],
        ];

        $this->command->info('🌍 Creando 6 solicitudes de prueba...');

        foreach ($requests as $index => $data) {
            $worker = $workers->firstWhere('id', $data['worker_id']);
            
            if (!$worker) {
                $this->command->warn("⚠️  Worker {$data['worker_id']} no encontrado, saltando solicitud");
                continue;
            }
            
            $lat = $worker->user->lat ?? -37.4689;
            $lng = $worker->user->lng ?? -72.3527;
            
            unset($data['lat'], $data['lng']);

            $request = ServiceRequest::create($data);

            DB::statement(
                "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$lng, $lat, $request->id]
            );

            $this->command->info("  ✓ #{$request->id} - {$data['type']} - {$data['description']}");

            event(new \App\Events\ServiceRequestCreated($request));
        }

        $this->command->info('✅ 6 solicitudes creadas exitosamente');
        $this->command->info('📊 Distribución:');
        $this->command->info('   • 2 Movilidad (ride_share)');
        $this->command->info('   • 2 Compras (express_errand)');
        $this->command->info('   • 2 Oficios (fixed_job)');
        $this->command->info('🗺️  Ubicación: En la posición de cada worker');
        $this->command->info('💰 Los pines dorados aparecerán en el mapa sobre los workers');
    }
}

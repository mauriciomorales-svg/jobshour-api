<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServiceRequestTypesSeeder extends Seeder
{
    public function run(): void
    {
        // Obtener usuarios de prueba
        $clients = User::limit(5)->get();
        
        if ($clients->isEmpty()) {
            $this->command->warn('No hay usuarios en la BD. Crea usuarios primero.');
            return;
        }

        // Coordenadas de Los Ángeles, Chile
        $losAngeles = ['lat' => -37.4689, 'lng' => -72.3527];
        $concepcion = ['lat' => -36.8270, 'lng' => -73.0498];
        $angol = ['lat' => -37.7974, 'lng' => -72.7111];

        $this->command->info('🟡 Seeding FIXED_JOB (Oficios)...');
        $this->seedFixedJobs($clients, $losAngeles);

        $this->command->info('🔵 Seeding RIDE_SHARE (Modo Viaje)...');
        $this->seedRideShares($clients, $losAngeles, $concepcion, $angol);

        $this->command->info('🟣 Seeding EXPRESS_ERRAND (Mandados)...');
        $this->seedExpressErrands($clients, $losAngeles);

        $this->command->info('✅ Seeder completado. 30 solicitudes creadas.');
    }

    private function seedFixedJobs($clients, $location)
    {
        $jobs = [
            [
                'description' => 'Necesito electricista para instalar luminarias LED',
                'offered_price' => 25000,
                'urgency' => 'high',
                'payload' => [
                    'skills' => ['Electricidad', 'Instalación LED'],
                    'hourly_rate' => 15000,
                    'estimated_hours' => 2,
                ],
            ],
            [
                'description' => 'Reparación de muebles de cocina',
                'offered_price' => 35000,
                'urgency' => 'medium',
                'payload' => [
                    'skills' => ['Carpintería', 'Reparación'],
                    'hourly_rate' => 12000,
                    'estimated_hours' => 3,
                ],
            ],
            [
                'description' => 'Pintura de habitación (20m²)',
                'offered_price' => 45000,
                'urgency' => 'low',
                'payload' => [
                    'skills' => ['Pintura', 'Acabados'],
                    'hourly_rate' => 10000,
                    'estimated_hours' => 4,
                ],
            ],
            [
                'description' => 'Instalación de cerámica en baño',
                'offered_price' => 80000,
                'urgency' => 'high',
                'payload' => [
                    'skills' => ['Cerámica', 'Albañilería'],
                    'hourly_rate' => 18000,
                    'estimated_hours' => 5,
                ],
            ],
            [
                'description' => 'Reparación de notebook (no enciende)',
                'offered_price' => 20000,
                'urgency' => 'medium',
                'payload' => [
                    'skills' => ['Técnico Computación', 'Hardware'],
                    'hourly_rate' => 15000,
                    'estimated_hours' => 1,
                ],
            ],
        ];

        foreach ($jobs as $job) {
            $sr = ServiceRequest::create([
                'client_id' => $clients->random()->id,
                'category_id' => 1,
                'category_type' => 'fixed',
                'description' => $job['description'],
                'offered_price' => $job['offered_price'],
                'urgency' => $job['urgency'],
                'status' => 'pending',
                'payload' => $job['payload'],
                'pin_expires_at' => now()->addMinutes(rand(30, 120)),
            ]);

            // Ubicación con variación aleatoria
            $lat = $location['lat'] + (rand(-100, 100) * 0.001);
            $lng = $location['lng'] + (rand(-100, 100) * 0.001);

            DB::update(
                "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$lng, $lat, $sr->id]
            );
        }
    }

    private function seedRideShares($clients, $origin, $destination1, $destination2)
    {
        $rides = [
            [
                'description' => 'Viaje Los Ángeles → Concepción',
                'offered_price' => 5000,
                'urgency' => 'high',
                'destination' => $destination1,
                'payload' => [
                    'available_seats' => 3,
                    'departure_time' => now()->addHours(2)->toIso8601String(),
                    'destination' => 'Concepción',
                    'vehicle_type' => 'Sedan',
                ],
            ],
            [
                'description' => 'Viaje Los Ángeles → Angol',
                'offered_price' => 3000,
                'urgency' => 'medium',
                'destination' => $destination2,
                'payload' => [
                    'available_seats' => 2,
                    'departure_time' => now()->addHours(4)->toIso8601String(),
                    'destination' => 'Angol',
                    'vehicle_type' => 'SUV',
                ],
            ],
            [
                'description' => 'Viaje Concepción → Los Ángeles (vuelta)',
                'offered_price' => 4500,
                'urgency' => 'low',
                'destination' => $origin,
                'payload' => [
                    'available_seats' => 4,
                    'departure_time' => now()->addHours(6)->toIso8601String(),
                    'destination' => 'Los Ángeles',
                    'vehicle_type' => 'Van',
                ],
            ],
        ];

        foreach ($rides as $ride) {
            $sr = ServiceRequest::create([
                'client_id' => $clients->random()->id,
                'category_id' => 12,
                'category_type' => 'travel',
                'description' => $ride['description'],
                'offered_price' => $ride['offered_price'],
                'urgency' => $ride['urgency'],
                'status' => 'pending',
                'payload' => $ride['payload'],
                'pin_expires_at' => now()->addMinutes(rand(60, 180)),
            ]);

            // Ubicación origen
            $lat = $origin['lat'] + (rand(-50, 50) * 0.001);
            $lng = $origin['lng'] + (rand(-50, 50) * 0.001);

            DB::update(
                "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$lng, $lat, $sr->id]
            );

            // Ruta (LineString) desde origen a destino
            $destLat = $ride['destination']['lat'];
            $destLng = $ride['destination']['lng'];

            DB::update(
                "UPDATE service_requests SET route = ST_SetSRID(ST_MakeLine(ST_MakePoint(?, ?), ST_MakePoint(?, ?)), 4326) WHERE id = ?",
                [$lng, $lat, $destLng, $destLat, $sr->id]
            );
        }
    }

    private function seedExpressErrands($clients, $location)
    {
        $errands = [
            [
                'description' => 'Compras en Jumbo (lista de 15 items)',
                'offered_price' => 8000,
                'urgency' => 'high',
                'payload' => [
                    'store_name' => 'Jumbo',
                    'item_list' => ['Leche', 'Pan', 'Huevos', 'Arroz', 'Aceite', 'Azúcar', 'Café', 'Té', 'Galletas', 'Queso', 'Jamón', 'Tomate', 'Lechuga', 'Cebolla', 'Papas'],
                    'load_type' => 'medium',
                    'estimated_weight_kg' => 10,
                ],
            ],
            [
                'description' => 'Retiro de materiales en Sodimac',
                'offered_price' => 12000,
                'urgency' => 'medium',
                'payload' => [
                    'store_name' => 'Sodimac',
                    'item_list' => ['Cemento 25kg', 'Arena 1 saco', 'Ladrillos x50'],
                    'load_type' => 'heavy',
                    'estimated_weight_kg' => 75,
                ],
            ],
            [
                'description' => 'Compra de medicamentos en Cruz Verde',
                'offered_price' => 5000,
                'urgency' => 'high',
                'payload' => [
                    'store_name' => 'Cruz Verde',
                    'item_list' => ['Paracetamol', 'Ibuprofeno', 'Vitamina C'],
                    'load_type' => 'light',
                    'estimated_weight_kg' => 0.5,
                ],
            ],
            [
                'description' => 'Retiro de pedido en Mercado Libre (Punto Pack)',
                'offered_price' => 6000,
                'urgency' => 'low',
                'payload' => [
                    'store_name' => 'Punto Pack',
                    'item_list' => ['Paquete pequeño'],
                    'load_type' => 'light',
                    'estimated_weight_kg' => 2,
                ],
            ],
        ];

        foreach ($errands as $errand) {
            $sr = ServiceRequest::create([
                'client_id' => $clients->random()->id,
                'category_id' => 11,
                'category_type' => 'errand',
                'description' => $errand['description'],
                'offered_price' => $errand['offered_price'],
                'urgency' => $errand['urgency'],
                'status' => 'pending',
                'payload' => $errand['payload'],
                'pin_expires_at' => now()->addMinutes(rand(30, 90)),
            ]);

            // Ubicación con variación
            $lat = $location['lat'] + (rand(-80, 80) * 0.001);
            $lng = $location['lng'] + (rand(-80, 80) * 0.001);

            DB::update(
                "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$lng, $lat, $sr->id]
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Worker;
use App\Models\Category;
use App\Models\ServiceRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RenaicoTestSeeder extends Seeder
{
    /**
     * Seeder completo y limpio para pruebas en Renaico
     * Incluye usuarios, workers, ServiceRequests de todos los tipos y datos de chat
     */
    public function run(): void
    {
        $this->command->info('🧹 Limpiando datos anteriores...');
        
        // Limpiar en orden correcto respetando foreign keys (PostgreSQL)
        // Primero las tablas dependientes
        DB::table('messages')->delete();
        DB::table('reviews')->delete();
        DB::table('service_disputes')->delete();
        DB::table('service_requests')->delete();
        DB::table('jobs')->delete();
        DB::table('workers')->delete();
        
        // Finalmente usuarios (excepto admin)
        DB::table('users')->where('email', '!=', 'admin@jobshour.cl')->delete();
        
        // Resetear secuencias de PostgreSQL
        try {
            DB::statement("SELECT setval(pg_get_serial_sequence('users', 'id'), 1, false)");
            DB::statement("SELECT setval(pg_get_serial_sequence('workers', 'id'), 1, false)");
            DB::statement("SELECT setval(pg_get_serial_sequence('service_requests', 'id'), 1, false)");
            DB::statement("SELECT setval(pg_get_serial_sequence('messages', 'id'), 1, false)");
        } catch (\Exception $e) {
            // Ignorar errores de secuencias si las tablas no existen aún
        }
        
        $this->command->info('✅ Datos limpiados completamente');
        
        // ── Centro de Renaico ──
        $centerLat = -37.6672;
        $centerLng = -72.5730;
        
        // ── Obtener categorías ──
        $categories = Category::all()->keyBy('slug');
        
        // ── CREAR USUARIOS PRINCIPALES ──
        $this->command->info('👤 Creando usuarios principales...');
        
        // Usuario 1: Mauricio Morales
        $mauricio = User::create([
            'name' => 'Mauricio Morales',
            'email' => 'mauricio.morales@usach.cl',
            'password' => Hash::make('password123'),
            'phone' => '+56912345678',
            'type' => 'worker',
            'avatar' => 'https://i.pravatar.cc/200?img=12',
            'provider' => 'google',
            'provider_id' => '117234567890123456789',
            'is_active' => true,
        ]);
        
        // Usuario 2: Comercial Isabel
        $isabel = User::create([
            'name' => 'Comercial Isabel',
            'email' => 'comercialisabel2020@gmail.com',
            'password' => Hash::make('password123'),
            'phone' => '+56987654321',
            'type' => 'employer',
            'avatar' => 'https://i.pravatar.cc/200?img=45',
            'is_active' => true,
        ]);
        
        $this->command->info("✅ Usuarios creados: {$mauricio->email}, {$isabel->email}");
        
        // ── CREAR WORKER PARA MAURICIO ──
        $mauricioWorker = Worker::create([
            'user_id' => $mauricio->id,
            'category_id' => $categories['electricidad']->id ?? null,
            'title' => 'Electricista Profesional',
            'bio' => 'Ingeniero eléctrico con experiencia en instalaciones residenciales y comerciales.',
            'skills' => ['electricidad', 'instalaciones', 'mantención', 'proyectos eléctricos'],
            'hourly_rate' => 25000,
            'availability_status' => 'active',
            'last_seen_at' => now()->subMinutes(5),
            'location_accuracy' => 10,
            'total_jobs_completed' => 45,
            'rating' => 4.9,
            'rating_count' => 45,
            'is_verified' => true,
        ]);
        
        // Ubicación de Mauricio (centro de Renaico)
        DB::statement(
            "UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
            [$centerLng, $centerLat, $mauricioWorker->id]
        );
        
        // ── CREAR OTROS WORKERS PARA PRUEBAS ──
        $otherWorkers = [
            [
                'name' => 'Juan Pérez',
                'email' => 'juan.perez@test.cl',
                'category' => 'gasfiteria',
                'title' => 'Gasfitería Pro',
                'hourly_rate' => 15000,
                'lat' => -37.6620,
                'lng' => -72.5680,
            ],
            [
                'name' => 'Marta Soto',
                'email' => 'marta.soto@test.cl',
                'category' => 'pintura',
                'title' => 'Pintora Profesional',
                'hourly_rate' => 18000,
                'lat' => -37.6720,
                'lng' => -72.5780,
            ],
            [
                'name' => 'Carlos Torres',
                'email' => 'carlos.torres@test.cl',
                'category' => 'limpieza',
                'title' => 'Limpieza Integral',
                'hourly_rate' => 12000,
                'lat' => -37.6650,
                'lng' => -72.5650,
            ],
        ];
        
        $workerIds = [$mauricioWorker->id];
        
        foreach ($otherWorkers as $w) {
            $user = User::create([
                'name' => $w['name'],
                'email' => $w['email'],
                'password' => Hash::make('password123'),
                'phone' => '+569' . rand(10000000, 99999999),
                'type' => 'worker',
                'avatar' => 'https://i.pravatar.cc/200?img=' . rand(1, 70),
                'is_active' => true,
            ]);
            
            $worker = Worker::create([
                'user_id' => $user->id,
                'category_id' => $categories[$w['category']]->id ?? null,
                'title' => $w['title'],
                'bio' => 'Profesional con experiencia',
                'skills' => [$w['category']],
                'hourly_rate' => $w['hourly_rate'],
                'availability_status' => 'active',
                'last_seen_at' => now()->subMinutes(rand(1, 30)),
                'location_accuracy' => rand(5, 20),
                'total_jobs_completed' => rand(20, 100),
                'rating' => round(4.0 + (rand(0, 10) / 10), 1),
                'rating_count' => rand(10, 50),
                'is_verified' => rand(0, 1) === 1,
            ]);
            
            DB::statement(
                "UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$w['lng'], $w['lat'], $worker->id]
            );
            
            $workerIds[] = $worker->id;
        }
        
        $this->command->info('✅ Workers creados');
        
        // ── CREAR SERVICEREQUESTS ──
        $this->command->info('📋 Creando ServiceRequests...');
        
        // 1. ASIENTOS VACÍOS (ride_share) - NUEVOS REGISTROS
        $rideShareRequests = [
            [
                'client_id' => $isabel->id,
                'description' => 'Viaje Renaico → Victoria - Salida mañana',
                'type' => 'ride_share',
                'category_type' => 'travel',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 12000,
                'lat' => $centerLat + 0.0015,
                'lng' => $centerLng - 0.0008,
                'payload' => [
                    'seats' => 2,
                    'departure_time' => now()->addDay()->setTime(8, 0)->format('Y-m-d H:i:s'),
                    'destination_name' => 'Victoria',
                    'vehicle_type' => 'Sedán',
                ],
                'pin_expires_at' => now()->addHours(36),
            ],
            [
                'client_id' => $isabel->id,
                'description' => 'Viaje urgente Renaico → Los Ángeles',
                'type' => 'ride_share',
                'category_type' => 'travel',
                'status' => 'pending',
                'urgency' => 'urgent',
                'offered_price' => 15000,
                'lat' => $centerLat - 0.0005,
                'lng' => $centerLng + 0.0012,
                'payload' => [
                    'seats' => 3,
                    'departure_time' => now()->addHours(3)->format('Y-m-d H:i:s'),
                    'destination_name' => 'Los Ángeles',
                    'vehicle_type' => 'SUV',
                ],
                'pin_expires_at' => now()->addHours(6),
            ],
            [
                'client_id' => $isabel->id,
                'description' => 'Viaje compartido Renaico → Traiguén',
                'type' => 'ride_share',
                'category_type' => 'travel',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 7000,
                'lat' => $centerLat + 0.002,
                'lng' => $centerLng - 0.0015,
                'payload' => [
                    'seats' => 1,
                    'departure_time' => now()->addHours(5)->format('Y-m-d H:i:s'),
                    'destination_name' => 'Traiguén',
                    'vehicle_type' => 'Pickup',
                ],
                'pin_expires_at' => now()->addHours(8),
            ],
        ];
        
        // 2. COMPRAS DE SUPERMERCADO (express_errand) - NUEVOS REGISTROS
        $errandRequests = [
            [
                'client_id' => $isabel->id,
                'description' => 'Compra urgente en Supermercado Lider Renaico',
                'type' => 'express_errand',
                'category_type' => 'errand',
                'status' => 'pending',
                'urgency' => 'urgent',
                'offered_price' => 15000,
                'lat' => $centerLat - 0.0012,
                'lng' => $centerLng + 0.0018,
                'payload' => [
                    'store_name' => 'Supermercado Lider',
                    'items_count' => 15,
                    'load_type' => 'medium',
                    'requires_vehicle' => true,
                ],
                'pin_expires_at' => now()->addHours(4),
            ],
            [
                'client_id' => $isabel->id,
                'description' => 'Compra de abarrotes y productos básicos',
                'type' => 'express_errand',
                'category_type' => 'errand',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 10000,
                'lat' => $centerLat + 0.0018,
                'lng' => $centerLng - 0.0012,
                'payload' => [
                    'store_name' => 'Abarrotes del Centro',
                    'items_count' => 25,
                    'load_type' => 'large',
                    'requires_vehicle' => true,
                ],
                'pin_expires_at' => now()->addHours(12),
            ],
            [
                'client_id' => $isabel->id,
                'description' => 'Retiro de medicamentos en Farmacia',
                'type' => 'express_errand',
                'category_type' => 'errand',
                'status' => 'pending',
                'urgency' => 'urgent',
                'offered_price' => 8000,
                'lat' => $centerLat - 0.0008,
                'lng' => $centerLng + 0.002,
                'payload' => [
                    'store_name' => 'Farmacia Cruz Verde',
                    'items_count' => 3,
                    'load_type' => 'small',
                    'requires_vehicle' => false,
                ],
                'pin_expires_at' => now()->addHours(3),
            ],
        ];
        
        // 3. TRABAJOS TRADICIONALES (fixed_job) - NUEVOS REGISTROS
        $fixedJobRequests = [
            [
                'client_id' => $isabel->id,
                'worker_id' => null,
                'category_id' => $categories['gasfiteria']->id ?? null,
                'description' => 'Reparación urgente de cañería rota',
                'type' => 'fixed_job',
                'category_type' => 'fixed',
                'status' => 'pending',
                'urgency' => 'urgent',
                'offered_price' => 30000,
                'lat' => $centerLat - 0.0015,
                'lng' => $centerLng + 0.0015,
                'payload' => [
                    'category' => 'Gasfitería',
                    'tools_provided' => false,
                    'estimated_hours' => 3,
                ],
                'pin_expires_at' => now()->addHours(6),
            ],
            [
                'client_id' => $isabel->id,
                'category_id' => $categories['electricidad']->id ?? null,
                'description' => 'Instalación de lámparas LED en casa',
                'type' => 'fixed_job',
                'category_type' => 'fixed',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 45000,
                'lat' => $centerLat + 0.002,
                'lng' => $centerLng - 0.002,
                'payload' => [
                    'category' => 'Electricidad',
                    'tools_provided' => true,
                    'estimated_hours' => 4,
                ],
                'pin_expires_at' => now()->addHours(24),
            ],
            [
                'client_id' => $isabel->id,
                'category_id' => $categories['pintura']->id ?? null,
                'description' => 'Pintura exterior de casa completa',
                'type' => 'fixed_job',
                'category_type' => 'fixed',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 80000,
                'lat' => $centerLat + 0.001,
                'lng' => $centerLng + 0.0025,
                'payload' => [
                    'category' => 'Pintura',
                    'tools_provided' => false,
                    'estimated_hours' => 8,
                ],
                'pin_expires_at' => now()->addHours(48),
            ],
            [
                'client_id' => $isabel->id,
                'category_id' => $categories['limpieza']->id ?? null,
                'description' => 'Limpieza profunda de oficina',
                'type' => 'fixed_job',
                'category_type' => 'fixed',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 40000,
                'lat' => $centerLat - 0.002,
                'lng' => $centerLng - 0.001,
                'payload' => [
                    'category' => 'Limpieza',
                    'tools_provided' => true,
                    'estimated_hours' => 5,
                ],
                'pin_expires_at' => now()->addHours(18),
            ],
            [
                'client_id' => $isabel->id,
                'category_id' => $categories['jardineria']->id ?? null,
                'description' => 'Poda de árboles y mantención de jardín',
                'type' => 'fixed_job',
                'category_type' => 'fixed',
                'status' => 'pending',
                'urgency' => 'normal',
                'offered_price' => 35000,
                'lat' => $centerLat + 0.0015,
                'lng' => $centerLng + 0.001,
                'payload' => [
                    'category' => 'Jardinería',
                    'tools_provided' => true,
                    'estimated_hours' => 4,
                ],
                'pin_expires_at' => now()->addHours(30),
            ],
        ];
        
        // Crear todos los ServiceRequests
        $allRequests = array_merge($rideShareRequests, $errandRequests, $fixedJobRequests);
        
        foreach ($allRequests as $req) {
            $serviceRequest = ServiceRequest::create([
                'client_id' => $req['client_id'],
                'worker_id' => $req['worker_id'] ?? null,
                'category_id' => $req['category_id'] ?? null,
                'description' => $req['description'],
                'type' => $req['type'],
                'category_type' => $req['category_type'],
                'status' => $req['status'],
                'urgency' => $req['urgency'],
                'offered_price' => $req['offered_price'],
                'payload' => $req['payload'],
                'pin_expires_at' => $req['pin_expires_at'],
            ]);
            
            // Establecer ubicación con PostGIS
            DB::statement(
                "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$req['lng'], $req['lat'], $serviceRequest->id]
            );
        }
        
        $this->command->info('✅ ServiceRequests creados: ' . count($allRequests));
        
        // ── CREAR SERVICEREQUEST ADICIONAL PARA CHAT (con worker asignado) ──
        $this->command->info('💬 Creando ServiceRequest para chat...');
        
        $chatServiceRequest = ServiceRequest::create([
            'client_id' => $isabel->id,
            'worker_id' => $mauricioWorker->id, // Asignado a Mauricio para chat
            'category_id' => $categories['gasfiteria']->id ?? null,
            'description' => 'Reparación urgente de cañería en cocina',
            'type' => 'fixed_job',
            'category_type' => 'fixed',
            'status' => 'accepted', // Aceptado para que tenga chat activo
            'urgency' => 'urgent',
            'offered_price' => 25000,
            'payload' => [
                'category' => 'Gasfitería',
                'tools_provided' => false,
                'estimated_hours' => 2,
            ],
            'accepted_at' => now()->subHours(2),
        ]);
        
        // Establecer ubicación para el chat request
        DB::statement(
            "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
            [$centerLng + 0.001, $centerLat - 0.001, $chatServiceRequest->id]
        );
        
        $this->command->info('✅ ServiceRequest para chat creado (ID: ' . $chatServiceRequest->id . ')');
        
        // ── CREAR MENSAJES DE CHAT ──
        $this->command->info('💬 Creando mensajes de chat...');
        
        // Usar el ServiceRequest creado específicamente para chat
        $chatRequest = $chatServiceRequest;
        
        if ($chatRequest) {
            $messages = [
                [
                    'service_request_id' => $chatRequest->id,
                    'sender_id' => $isabel->id,
                    'body' => 'Hola, necesito que repares una cañería que está goteando en la cocina.',
                    'type' => 'text',
                    'created_at' => now()->subHours(2),
                    'updated_at' => now()->subHours(2),
                ],
                [
                    'service_request_id' => $chatRequest->id,
                    'sender_id' => $mauricio->id,
                    'body' => 'Hola Isabel, claro. ¿Puedes enviarme una foto del problema?',
                    'type' => 'text',
                    'created_at' => now()->subHours(1)->subMinutes(50),
                    'updated_at' => now()->subHours(1)->subMinutes(50),
                ],
                [
                    'service_request_id' => $chatRequest->id,
                    'sender_id' => $isabel->id,
                    'body' => 'Sí, te la envío ahora mismo.',
                    'type' => 'text',
                    'created_at' => now()->subHours(1)->subMinutes(40),
                    'updated_at' => now()->subHours(1)->subMinutes(40),
                ],
                [
                    'service_request_id' => $chatRequest->id,
                    'sender_id' => $mauricio->id,
                    'body' => 'Perfecto, veo el problema. Puedo ir mañana en la mañana, ¿te funciona?',
                    'type' => 'text',
                    'created_at' => now()->subHours(1)->subMinutes(30),
                    'updated_at' => now()->subHours(1)->subMinutes(30),
                ],
                [
                    'service_request_id' => $chatRequest->id,
                    'sender_id' => $isabel->id,
                    'body' => 'Sí, perfecto. ¿A qué hora aproximadamente?',
                    'type' => 'text',
                    'created_at' => now()->subHours(1)->subMinutes(20),
                    'updated_at' => now()->subHours(1)->subMinutes(20),
                ],
                [
                    'service_request_id' => $chatRequest->id,
                    'sender_id' => $mauricio->id,
                    'body' => 'Entre 9 y 10 de la mañana. Te confirmo cuando salga.',
                    'type' => 'text',
                    'created_at' => now()->subHours(1)->subMinutes(10),
                    'updated_at' => now()->subHours(1)->subMinutes(10),
                ],
            ];
            
            foreach ($messages as $msg) {
                DB::table('messages')->insert($msg);
            }
            
            $this->command->info('✅ Mensajes de chat creados: ' . count($messages));
        }
        
        $this->command->info('');
        $this->command->info('✅ Seeder completado exitosamente!');
        $this->command->info('');
        $this->command->info('📊 Resumen:');
        $this->command->info('   - Usuarios: 2 principales + ' . count($otherWorkers) . ' workers');
        $this->command->info('   - ServiceRequests: ' . count($allRequests));
        $this->command->info('     • Asientos vacíos (ride_share): ' . count($rideShareRequests));
        $this->command->info('     • Compras supermercado (express_errand): ' . count($errandRequests));
        $this->command->info('     • Trabajos tradicionales (fixed_job): ' . count($fixedJobRequests));
        $this->command->info('   - Mensajes de chat: ' . ($chatRequest ? count($messages) : 0));
        $this->command->info('');
        $this->command->info('🔑 Credenciales de prueba:');
        $this->command->info('   Mauricio: mauricio.morales@usach.cl / password123');
        $this->command->info('   Isabel: comercialisabel2020@gmail.com / password123');
        $this->command->info('');
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Worker;
use App\Models\Job;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Ejecutar seeder principal de Renaico
        $this->call(RenaicoTestSeeder::class);
        return;
        
        // Código anterior comentado - usar RenaicoTestSeeder en su lugar
        // ── Centro de Renaico como referencia ──
        $centerLat = -37.6672;
        $centerLng = -72.5730;

        // ── CATEGORIES ──
        $categories = [
            ['slug' => 'gasfiteria',    'display_name' => 'Gasfitería',    'icon' => 'wrench',       'color' => '#2563eb', 'sort_order' => 1],
            ['slug' => 'electricidad',  'display_name' => 'Electricidad',  'icon' => 'zap',          'color' => '#f59e0b', 'sort_order' => 2],
            ['slug' => 'pintura',       'display_name' => 'Pintura',       'icon' => 'paintbrush',   'color' => '#8b5cf6', 'sort_order' => 3],
            ['slug' => 'limpieza',      'display_name' => 'Limpieza',      'icon' => 'sparkles',     'color' => '#06b6d4', 'sort_order' => 4],
            ['slug' => 'carpinteria',   'display_name' => 'Carpintería',   'icon' => 'hammer',       'color' => '#d97706', 'sort_order' => 5],
            ['slug' => 'jardineria',    'display_name' => 'Jardinería',    'icon' => 'leaf',         'color' => '#059669', 'sort_order' => 6],
            ['slug' => 'cerrajeria',    'display_name' => 'Cerrajería',    'icon' => 'key',          'color' => '#dc2626', 'sort_order' => 7],
            ['slug' => 'albanileria',   'display_name' => 'Albañilería',   'icon' => 'building',     'color' => '#78716c', 'sort_order' => 8],
            ['slug' => 'costura',       'display_name' => 'Costura',       'icon' => 'scissors',     'color' => '#ec4899', 'sort_order' => 9],
            ['slug' => 'mascotas',      'display_name' => 'Mascotas',      'icon' => 'paw-print',    'color' => '#a855f7', 'sort_order' => 10],
        ];

        $categoryMap = [];
        foreach ($categories as $c) {
            $cat = Category::create($c);
            $categoryMap[$c['slug']] = $cat->id;
        }

        // ── WORKERS ── (Distribuidos por diferentes sectores de Renaico y alrededores)
        $workers = [
            [
                'name' => 'Juan Pérez',
                'email' => 'juan.perez@jobshour.cl',
                'phone' => '+56911111111',
                'avatar' => 'https://i.pravatar.cc/200?img=12',
                'title' => 'Gasfitería Pro',
                'bio' => 'Más de 10 años de experiencia en gasfitería residencial e industrial. Trabajos garantizados.',
                'skills' => ['gasfitería', 'plomería', 'instalación sanitaria', 'destape cañerías'],
                'category_slug' => 'gasfiteria',
                'hourly_rate' => 15000,
                'rating' => 4.9,
                'rating_count' => 87,
                'total_jobs_completed' => 134,
                'lat' => -37.6620,  // Sector Norte - Av. Bernardo O'Higgins
                'lng' => -72.5680,
            ],
            [
                'name' => 'Marta Soto',
                'email' => 'marta.soto@jobshour.cl',
                'phone' => '+56922222222',
                'avatar' => 'https://i.pravatar.cc/200?img=45',
                'title' => 'Electricidad Certificada',
                'bio' => 'Técnica certificada SEC. Instalaciones eléctricas domiciliarias, tableros, iluminación LED.',
                'skills' => ['electricidad', 'tableros eléctricos', 'iluminación', 'SEC certificada'],
                'category_slug' => 'electricidad',
                'hourly_rate' => 22000,
                'rating' => 5.0,
                'rating_count' => 62,
                'total_jobs_completed' => 98,
                'lat' => -37.6720,  // Sector Sur - Villa Los Aromos
                'lng' => -72.5780,
            ],
            [
                'name' => 'Carlos Torres',
                'email' => 'carlos.torres@jobshour.cl',
                'phone' => '+56933333333',
                'avatar' => 'https://i.pravatar.cc/200?img=33',
                'title' => 'Pintura & Acabados',
                'bio' => 'Pintor profesional. Interior, exterior, estuco, empaste. Colores y texturas a pedido.',
                'skills' => ['pintura', 'estuco', 'empaste', 'acabados', 'barniz'],
                'category_slug' => 'pintura',
                'hourly_rate' => 18000,
                'rating' => 4.7,
                'rating_count' => 45,
                'total_jobs_completed' => 76,
                'lat' => -37.6650,  // Sector Este - Población Esperanza
                'lng' => -72.5650,
            ],
            [
                'name' => 'Elena Rivas',
                'email' => 'elena.rivas@jobshour.cl',
                'phone' => '+56944444444',
                'avatar' => 'https://i.pravatar.cc/200?img=47',
                'title' => 'Limpieza Integral',
                'bio' => 'Servicio de aseo profundo para hogares y oficinas. Limpieza post-construcción.',
                'skills' => ['limpieza profunda', 'aseo industrial', 'post-construcción', 'sanitización'],
                'category_slug' => 'limpieza',
                'hourly_rate' => 12000,
                'rating' => 4.8,
                'rating_count' => 120,
                'total_jobs_completed' => 210,
                'lat' => -37.6690,  // Sector Oeste - Calle Comercio
                'lng' => -72.5820,
            ],
            [
                'name' => 'Roberto Muñoz',
                'email' => 'roberto.munoz@jobshour.cl',
                'phone' => '+56955555555',
                'avatar' => 'https://i.pravatar.cc/200?img=11',
                'title' => 'Carpintería & Muebles',
                'bio' => 'Muebles a medida, puertas, closets, cocinas. Trabajo en madera nativa y MDF.',
                'skills' => ['carpintería', 'muebles a medida', 'closets', 'cocinas', 'puertas'],
                'category_slug' => 'carpinteria',
                'hourly_rate' => 20000,
                'rating' => 4.6,
                'rating_count' => 38,
                'total_jobs_completed' => 55,
                'lat' => -37.6672,  // Centro - Santiago Watt (cerca DondeMorales)
                'lng' => -72.5730,
            ],
            [
                'name' => 'Andrea López',
                'email' => 'andrea.lopez@jobshour.cl',
                'phone' => '+56966666666',
                'avatar' => 'https://i.pravatar.cc/200?img=32',
                'title' => 'Jardinería & Paisajismo',
                'bio' => 'Diseño de jardines, poda, mantención de áreas verdes, riego automático.',
                'skills' => ['jardinería', 'paisajismo', 'poda', 'riego automático', 'césped'],
                'category_slug' => 'jardineria',
                'hourly_rate' => 14000,
                'rating' => 4.9,
                'rating_count' => 73,
                'total_jobs_completed' => 145,
                'lat' => -37.6600,  // Sector Noreste - Villa Los Alerces
                'lng' => -72.5700,
            ],
            [
                'name' => 'Diego Fuentes',
                'email' => 'diego.fuentes@jobshour.cl',
                'phone' => '+56977777777',
                'avatar' => 'https://i.pravatar.cc/200?img=53',
                'title' => 'Cerrajería 24hrs',
                'bio' => 'Apertura de puertas, cambio de chapas, instalación de cerraduras de seguridad.',
                'skills' => ['cerrajería', 'chapas', 'cerraduras', 'apertura emergencia'],
                'category_slug' => 'cerrajeria',
                'hourly_rate' => 25000,
                'rating' => 4.5,
                'rating_count' => 56,
                'total_jobs_completed' => 89,
                'lat' => -37.6740,  // Sector Suroeste - Av. La Paz
                'lng' => -72.5800,
            ],
            [
                'name' => 'Patricia Herrera',
                'email' => 'patricia.herrera@jobshour.cl',
                'phone' => '+56988888888',
                'avatar' => 'https://i.pravatar.cc/200?img=26',
                'title' => 'Costura & Arreglos',
                'bio' => 'Arreglos de ropa, confección a medida, cortinas, tapicería básica.',
                'skills' => ['costura', 'arreglos de ropa', 'confección', 'cortinas'],
                'category_slug' => 'costura',
                'hourly_rate' => 10000,
                'rating' => 4.8,
                'rating_count' => 95,
                'total_jobs_completed' => 180,
                'lat' => -37.6630,  // Sector Noroeste - Pasaje Los Cipreses
                'lng' => -72.5760,
            ],
            [
                'name' => 'Felipe Contreras',
                'email' => 'felipe.contreras@jobshour.cl',
                'phone' => '+56999999999',
                'avatar' => 'https://i.pravatar.cc/200?img=60',
                'title' => 'Albañilería & Construcción',
                'bio' => 'Obras menores, radier, muros, ampliaciones, reparaciones estructurales.',
                'skills' => ['albañilería', 'radier', 'muros', 'ampliaciones', 'cemento'],
                'category_slug' => 'albanileria',
                'hourly_rate' => 20000,
                'rating' => 4.7,
                'rating_count' => 41,
                'total_jobs_completed' => 67,
                'lat' => -37.6710,  // Sector Sureste - Calle Victoria
                'lng' => -72.5670,
            ],
            [
                'name' => 'Camila Navarro',
                'email' => 'camila.navarro@jobshour.cl',
                'phone' => '+56900000001',
                'avatar' => 'https://i.pravatar.cc/200?img=5',
                'title' => 'Cuidado de Mascotas',
                'bio' => 'Paseo de perros, cuidado a domicilio, baño y corte básico.',
                'skills' => ['cuidado mascotas', 'paseo perros', 'pet sitting', 'baño mascotas'],
                'category_slug' => 'mascotas',
                'hourly_rate' => 8000,
                'rating' => 5.0,
                'rating_count' => 110,
                'total_jobs_completed' => 230,
                'lat' => -37.6580,  // Sector Norte extremo - Pasaje Los Robles
                'lng' => -72.5720,
            ],
            [
                'name' => 'Mauricio Morales',
                'email' => 'mauricio.morales@usach.cl',
                'phone' => '+56912345678',
                'avatar' => 'https://lh3.googleusercontent.com/a/default-user',  // Se actualizará con foto real de Google al hacer login
                'title' => 'Electricidad Profesional',
                'bio' => 'Ingeniero eléctrico con experiencia en instalaciones residenciales y comerciales.',
                'skills' => ['electricidad', 'instalaciones', 'mantención', 'proyectos eléctricos'],
                'category_slug' => 'electricidad',
                'hourly_rate' => 25000,
                'rating' => 4.9,
                'rating_count' => 45,
                'total_jobs_completed' => 78,
                'lat' => -37.6655,  // Centro-Este de Renaico
                'lng' => -72.5710,
            ],
        ];

        // ── EMPLOYERS ──
        $employers = [
            ['name' => 'María González', 'email' => 'maria.gonzalez@gmail.com', 'phone' => '+56912000001', 'avatar' => 'https://i.pravatar.cc/200?img=20'],
            ['name' => 'Pedro Morales', 'email' => 'pedro.morales@gmail.com', 'phone' => '+56912000002', 'avatar' => 'https://i.pravatar.cc/200?img=15'],
            ['name' => 'Sofía Vargas', 'email' => 'sofia.vargas@gmail.com', 'phone' => '+56912000003', 'avatar' => 'https://i.pravatar.cc/200?img=25'],
            ['name' => 'Tomás Rojas', 'email' => 'tomas.rojas@gmail.com', 'phone' => '+56912000004', 'avatar' => 'https://i.pravatar.cc/200?img=52'],
            ['name' => 'Valentina Silva', 'email' => 'valentina.silva@gmail.com', 'phone' => '+56912000005', 'avatar' => 'https://i.pravatar.cc/200?img=44'],
        ];

        $workerIds = [];

        // Crear workers (user + worker profile)
        foreach ($workers as $w) {
            $userData = [
                'name' => $w['name'],
                'email' => $w['email'],
                'phone' => $w['phone'],
                'password' => Hash::make('password123'),
                'type' => 'worker',
                'avatar' => $w['avatar'],
                'is_active' => true,
            ];

            // Si es mauricio.morales@usach.cl, agregar datos de Google OAuth
            if ($w['email'] === 'mauricio.morales@usach.cl') {
                $userData['provider'] = 'google';
                $userData['provider_id'] = '117234567890123456789'; // ID ficticio de Google
                $userData['avatar_url'] = $w['avatar'];
            }

            $user = User::create($userData);

            $worker = Worker::create([
                'user_id' => $user->id,
                'category_id' => $categoryMap[$w['category_slug']] ?? null,
                'title' => $w['title'],
                'bio' => $w['bio'],
                'skills' => $w['skills'],
                'hourly_rate' => $w['hourly_rate'],
                'availability_status' => 'active',
                'last_seen_at' => now()->subMinutes(rand(1, 4)),
                'location_accuracy' => rand(5, 20),
                'total_jobs_completed' => $w['total_jobs_completed'],
                'rating' => $w['rating'],
                'rating_count' => $w['rating_count'],
                'is_verified' => rand(0, 1) ? true : false,
            ]);

            // Setear location con PostGIS (coordenadas reales de calles)
            DB::statement(
                "UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$w['lng'], $w['lat'], $worker->id]
            );

            $workerIds[] = $worker->id;
        }

        // Crear employers
        $employerUserIds = [];
        foreach ($employers as $e) {
            $user = User::create([
                'name' => $e['name'],
                'email' => $e['email'],
                'phone' => $e['phone'],
                'password' => Hash::make('password123'),
                'type' => 'employer',
                'avatar' => $e['avatar'],
                'is_active' => true,
            ]);
            $employerUserIds[] = $user->id;
        }

        // ── JOBS ──
        $jobs = [
            [
                'title' => 'Reparar cañería cocina',
                'description' => 'Cañería con filtración debajo del lavaplatos. Se necesita con urgencia.',
                'skills_required' => ['gasfitería', 'plomería'],
                'address' => 'Santiago Watt 205, Renaico',
                'budget' => 25000,
                'payment_type' => 'fixed',
                'urgency' => 'urgent',
                'status' => 'open',
                'lat_offset' => 0.001,
                'lng_offset' => -0.001,
            ],
            [
                'title' => 'Instalar luminarias LED living',
                'description' => 'Cambiar 6 focos halógenos por LED empotrados en cielo falso del living.',
                'skills_required' => ['electricidad', 'iluminación'],
                'address' => 'Av. Bernardo O\'Higgins 340, Renaico',
                'budget' => 45000,
                'payment_type' => 'fixed',
                'urgency' => 'medium',
                'status' => 'open',
                'lat_offset' => -0.002,
                'lng_offset' => 0.003,
            ],
            [
                'title' => 'Pintar dormitorio completo',
                'description' => 'Dormitorio de 4x4m, incluye cielo. Empaste y 2 manos de pintura. Color a definir.',
                'skills_required' => ['pintura', 'empaste'],
                'address' => 'Los Aromos 78, Renaico',
                'budget' => 60000,
                'payment_type' => 'fixed',
                'urgency' => 'low',
                'status' => 'open',
                'lat_offset' => 0.003,
                'lng_offset' => 0.002,
            ],
            [
                'title' => 'Aseo profundo departamento',
                'description' => 'Departamento de 2 dormitorios para entrega. Limpieza completa incluyendo cocina y baños.',
                'skills_required' => ['limpieza profunda', 'sanitización'],
                'address' => 'Calle Comercio 120, Renaico',
                'budget' => 35000,
                'payment_type' => 'fixed',
                'urgency' => 'high',
                'status' => 'open',
                'lat_offset' => -0.001,
                'lng_offset' => -0.002,
            ],
            [
                'title' => 'Fabricar closet empotrado',
                'description' => 'Closet empotrado de 2.4m de ancho con puertas correderas. Material: melamina blanca.',
                'skills_required' => ['carpintería', 'muebles a medida', 'closets'],
                'address' => 'Pasaje Los Cipreses 15, Renaico',
                'budget' => 180000,
                'payment_type' => 'fixed',
                'urgency' => 'low',
                'status' => 'open',
                'lat_offset' => 0.002,
                'lng_offset' => -0.003,
            ],
            [
                'title' => 'Podar árboles y mantención jardín',
                'description' => 'Poda de 3 árboles grandes y limpieza general del jardín. Retirar desechos.',
                'skills_required' => ['jardinería', 'poda'],
                'address' => 'Villa Los Alerces, Renaico',
                'budget' => 40000,
                'payment_type' => 'fixed',
                'urgency' => 'medium',
                'status' => 'open',
                'lat_offset' => -0.003,
                'lng_offset' => 0.001,
            ],
            [
                'title' => 'Cambiar chapa puerta principal',
                'description' => 'Chapa trabada, no se puede cerrar con llave. Necesito cambio urgente por seguridad.',
                'skills_required' => ['cerrajería', 'chapas'],
                'address' => 'Av. La Paz 450, Renaico',
                'budget' => 30000,
                'payment_type' => 'fixed',
                'urgency' => 'urgent',
                'status' => 'open',
                'lat_offset' => 0.000,
                'lng_offset' => 0.004,
            ],
            [
                'title' => 'Hacer radier para terraza',
                'description' => 'Radier de 3x5m para terraza trasera. Incluye preparación de terreno.',
                'skills_required' => ['albañilería', 'radier', 'cemento'],
                'address' => 'Población Esperanza 33, Renaico',
                'budget' => 250000,
                'payment_type' => 'fixed',
                'urgency' => 'medium',
                'status' => 'open',
                'lat_offset' => 0.004,
                'lng_offset' => 0.000,
            ],
            // Trabajos ya completados
            [
                'title' => 'Destape cañería baño',
                'description' => 'Cañería tapada en el baño principal.',
                'skills_required' => ['gasfitería', 'destape cañerías'],
                'address' => 'Calle Victoria 88, Renaico',
                'budget' => 20000,
                'payment_type' => 'fixed',
                'urgency' => 'high',
                'status' => 'completed',
                'worker_index' => 0,
                'lat_offset' => 0.001,
                'lng_offset' => 0.001,
            ],
            [
                'title' => 'Arreglo cortinas living',
                'description' => 'Acortar cortinas y arreglar dobladillo.',
                'skills_required' => ['costura', 'cortinas'],
                'address' => 'Pasaje Los Robles 7, Renaico',
                'budget' => 15000,
                'payment_type' => 'fixed',
                'urgency' => 'low',
                'status' => 'completed',
                'worker_index' => 7,
                'lat_offset' => -0.002,
                'lng_offset' => -0.001,
            ],
        ];

        foreach ($jobs as $j) {
            $employerId = $employerUserIds[array_rand($employerUserIds)];
            $workerId = isset($j['worker_index']) ? $workerIds[$j['worker_index']] : null;

            $job = Job::create([
                'employer_id' => $employerId,
                'worker_id' => $workerId,
                'title' => $j['title'],
                'description' => $j['description'],
                'skills_required' => $j['skills_required'],
                'address' => $j['address'],
                'budget' => $j['budget'],
                'payment_type' => $j['payment_type'],
                'urgency' => $j['urgency'],
                'status' => $j['status'],
                'estimated_duration_minutes' => rand(30, 240),
                'started_at' => $j['status'] === 'completed' ? now()->subDays(rand(1, 30)) : null,
                'completed_at' => $j['status'] === 'completed' ? now()->subDays(rand(0, 5)) : null,
                'final_price' => $j['status'] === 'completed' ? $j['budget'] : null,
            ]);

            $lat = $centerLat + $j['lat_offset'];
            $lng = $centerLng + $j['lng_offset'];
            DB::statement(
                "UPDATE jobs SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$lng, $lat, $job->id]
            );
        }

        echo "Seed completado: " . count($workers) . " workers, " . count($employers) . " employers, " . count($jobs) . " jobs\n";
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

/**
 * SEEDER DE TESTING - MODO VIAJE
 * 
 * Escenario de validación:
 * - Marco (Worker): Centro de Renaico → Angol
 * - Cliente A (Cerca): 1.2km de la Ruta 180 → DEBE MATCHEAR
 * - Cliente B (Lejos): 5km de la ruta → NO DEBE MATCHEAR
 * 
 * Objetivo: Validar que el sistema "absorba" solo a quien está en el camino
 */
class TravelModeTestSeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar usuarios de prueba existentes
        echo "🧹 Limpiando usuarios de prueba existentes...\n";
        User::whereIn('email', [
            'marco.test@jobshour.cl',
            'maria.test@jobshour.cl',
            'pedro.test@jobshour.cl',
        ])->delete();
        echo "   ✅ Usuarios anteriores eliminados\n\n";

        // Coordenadas reales de Renaico y Angol
        $renaicoLat = -37.6700;
        $renaicoLng = -72.5700;
        $angolLat = -37.8000;
        $angolLng = -72.7100;

        // Calcular punto en la Ruta 180 (intermedio entre Renaico y Angol)
        $midpointLat = ($renaicoLat + $angolLat) / 2; // -37.735
        $midpointLng = ($renaicoLng + $angolLng) / 2; // -72.640

        echo "🎬 Creando escenario de testing para Modo Viaje...\n\n";

        // ========================================
        // 1. WORKER: Marco (Tiene vehículo)
        // ========================================
        echo "👤 Creando Worker: Marco (Renaico → Angol)\n";
        
        $marco = User::create([
            'name' => 'Marco Pérez',
            'email' => 'marco.test@jobshour.cl',
            'phone' => '+56912345001',
            'password' => Hash::make('password123'),
            'avatar' => 'https://i.pravatar.cc/150?u=marco',
            'email_verified_at' => now(),
        ]);

        $marcoWorker = Worker::create([
            'user_id' => $marco->id,
            'title' => 'Conductor con experiencia',
            'bio' => 'Viajo frecuentemente entre Renaico y Angol. Puedo llevar pasajeros o encomiendas.',
            'hourly_rate' => 15000,
            'availability_status' => 'active',
            'is_verified' => true,
            'total_jobs_completed' => 45,
            'rating' => 4.8,
            'rating_count' => 32,
        ]);

        // Ubicar a Marco en el centro de Renaico
        DB::update("
            UPDATE workers 
            SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)
            WHERE id = ?
        ", [$renaicoLng, $renaicoLat, $marcoWorker->id]);

        echo "   ✅ Marco creado en Renaico ({$renaicoLat}, {$renaicoLng})\n";
        echo "   📧 Email: marco.test@jobshour.cl | Password: password123\n\n";

        // ========================================
        // 2. CLIENTE A: María (CERCA - 1.2km de la ruta)
        // ========================================
        echo "👤 Creando Cliente A: María (CERCA - debe matchear)\n";
        
        // Ubicación: 1.2km al este de la Ruta 180 (punto medio)
        // Aproximadamente 0.015 grados de longitud = ~1.2km
        $mariaLat = $midpointLat + 0.005; // Pequeño desvío norte
        $mariaLng = $midpointLng + 0.015; // 1.2km al este

        $maria = User::create([
            'name' => 'María González',
            'email' => 'maria.test@jobshour.cl',
            'phone' => '+56912345002',
            'password' => Hash::make('password123'),
            'avatar' => 'https://i.pravatar.cc/150?u=maria',
            'email_verified_at' => now(),
        ]);

        // María también necesita un worker profile (aunque sea inactivo)
        $mariaWorker = Worker::create([
            'user_id' => $maria->id,
            'availability_status' => 'inactive',
        ]);

        DB::update("
            UPDATE workers 
            SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)
            WHERE id = ?
        ", [$mariaLng, $mariaLat, $mariaWorker->id]);

        echo "   ✅ María creada a ~1.2km de la Ruta 180 ({$mariaLat}, {$mariaLng})\n";
        echo "   📧 Email: maria.test@jobshour.cl | Password: password123\n";
        echo "   🎯 DEBE APARECER en el match de Marco\n\n";

        // ========================================
        // 3. CLIENTE B: Pedro (LEJOS - 5km de la ruta)
        // ========================================
        echo "👤 Creando Cliente B: Pedro (LEJOS - NO debe matchear)\n";
        
        // Ubicación: 5km al oeste de la Ruta 180
        // Aproximadamente 0.065 grados de longitud = ~5km
        $pedroLat = $midpointLat - 0.010; // Pequeño desvío sur
        $pedroLng = $midpointLng - 0.065; // 5km al oeste

        $pedro = User::create([
            'name' => 'Pedro Ramírez',
            'email' => 'pedro.test@jobshour.cl',
            'phone' => '+56912345003',
            'password' => Hash::make('password123'),
            'avatar' => 'https://i.pravatar.cc/150?u=pedro',
            'email_verified_at' => now(),
        ]);

        $pedroWorker = Worker::create([
            'user_id' => $pedro->id,
            'availability_status' => 'inactive',
        ]);

        DB::update("
            UPDATE workers 
            SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)
            WHERE id = ?
        ", [$pedroLng, $pedroLat, $pedroWorker->id]);

        echo "   ✅ Pedro creado a ~5km de la Ruta 180 ({$pedroLat}, {$pedroLng})\n";
        echo "   📧 Email: pedro.test@jobshour.cl | Password: password123\n";
        echo "   ❌ NO DEBE APARECER en el match de Marco\n\n";

        // ========================================
        // RESUMEN DEL ESCENARIO
        // ========================================
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "✅ ESCENARIO DE TESTING CREADO\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        echo "🚗 WORKER:\n";
        echo "   Marco (ID: {$marcoWorker->id})\n";
        echo "   Ubicación: Renaico ({$renaicoLat}, {$renaicoLng})\n";
        echo "   Destino: Angol ({$angolLat}, {$angolLng})\n\n";

        echo "👥 CLIENTES:\n";
        echo "   ✅ María (ID: {$maria->id}) - CERCA (1.2km) - DEBE MATCHEAR\n";
        echo "   ❌ Pedro (ID: {$pedro->id}) - LEJOS (5km) - NO DEBE MATCHEAR\n\n";

        echo "🔑 CREDENCIALES:\n";
        echo "   Todos los usuarios: password123\n\n";

        echo "📍 COORDENADAS GUARDADAS:\n";
        echo "   Marco Worker ID: {$marcoWorker->id}\n";
        echo "   María User ID: {$maria->id}\n";
        echo "   Pedro User ID: {$pedro->id}\n\n";

        // Guardar IDs en archivo para scripts de testing
        file_put_contents(
            database_path('seeders/travel_mode_test_ids.json'),
            json_encode([
                'marco_user_id' => $marco->id,
                'marco_worker_id' => $marcoWorker->id,
                'maria_user_id' => $maria->id,
                'maria_worker_id' => $mariaWorker->id,
                'pedro_user_id' => $pedro->id,
                'pedro_worker_id' => $pedroWorker->id,
                'renaico' => ['lat' => $renaicoLat, 'lng' => $renaicoLng],
                'angol' => ['lat' => $angolLat, 'lng' => $angolLng],
                'maria_location' => ['lat' => $mariaLat, 'lng' => $mariaLng],
                'pedro_location' => ['lat' => $pedroLat, 'lng' => $pedroLng],
            ], JSON_PRETTY_PRINT)
        );

        echo "💾 IDs guardados en: database/seeders/travel_mode_test_ids.json\n\n";
    }
}

<?php
// Script para verificar por qué un worker no aparece en el mapa
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Worker;
use App\Models\User;

$email = 'comercialisabel2020@gmail.com';

echo "🔍 Verificando worker para: $email\n\n";

$user = User::where('email', $email)->first();

if (!$user) {
    echo "❌ Usuario no encontrado\n";
    exit(1);
}

echo "✅ Usuario encontrado:\n";
echo "   ID: {$user->id}\n";
echo "   Nombre: {$user->name}\n";
echo "   Email: {$user->email}\n\n";

$worker = Worker::where('user_id', $user->id)->first();

if (!$worker) {
    echo "❌ Worker no encontrado\n";
    exit(1);
}

echo "✅ Worker encontrado:\n";
echo "   Worker ID: {$worker->id}\n";
echo "   User ID: {$worker->user_id}\n";
echo "   Category ID: " . ($worker->category_id ?? 'NULL') . "\n";
echo "   Availability Status: " . ($worker->availability_status ?? 'NULL') . "\n";
echo "   User Mode: " . ($worker->user_mode ?? 'NULL') . "\n";
echo "   Is Visible: " . ($worker->is_visible ? 'true' : 'false') . "\n";

// Verificar ubicación
$location = DB::selectOne("
    SELECT 
        ST_X(location::geometry) as lng,
        ST_Y(location::geometry) as lat
    FROM workers 
    WHERE id = ?
", [$worker->id]);

if ($location && $location->lat && $location->lng) {
    echo "   Location: Lat {$location->lat}, Lng {$location->lng}\n";
} else {
    echo "   Location: ❌ NO TIENE UBICACIÓN\n";
}

echo "\n🔍 Verificando condiciones para aparecer en el mapa:\n\n";

$issues = [];

// Condición 1: user_mode debe ser 'socio' o NULL
if ($worker->user_mode !== 'socio' && $worker->user_mode !== null) {
    $issues[] = "❌ user_mode debe ser 'socio' o NULL (actual: {$worker->user_mode})";
} else {
    echo "✅ user_mode: " . ($worker->user_mode ?? 'NULL') . " (correcto)\n";
}

// Condición 2: availability_status debe ser 'active' o 'intermediate'
if (!in_array($worker->availability_status, ['active', 'intermediate'])) {
    $issues[] = "❌ availability_status debe ser 'active' o 'intermediate' (actual: {$worker->availability_status})";
} else {
    echo "✅ availability_status: {$worker->availability_status} (correcto)\n";
}

// Condición 3: Debe tener ubicación
if (!$location || !$location->lat || !$location->lng) {
    $issues[] = "❌ No tiene ubicación establecida";
} else {
    echo "✅ Location: Lat {$location->lat}, Lng {$location->lng} (correcto)\n";
}

// Condición 4: Debe tener category_id (para active/intermediate)
if (in_array($worker->availability_status, ['active', 'intermediate']) && !$worker->category_id) {
    $issues[] = "❌ Debe tener category_id cuando está active o intermediate";
} else {
    echo "✅ category_id: " . ($worker->category_id ?? 'NULL') . "\n";
}

echo "\n";

if (empty($issues)) {
    echo "✅ Todas las condiciones están correctas. El worker debería aparecer en el mapa.\n";
    echo "\n💡 Si aún no aparece, verifica:\n";
    echo "   1. Que el mapa esté buscando en el radio correcto\n";
    echo "   2. Que la ubicación del worker esté dentro del radio de búsqueda\n";
    echo "   3. Que no haya filtros de categoría aplicados\n";
} else {
    echo "⚠️ PROBLEMAS ENCONTRADOS:\n\n";
    foreach ($issues as $issue) {
        echo "   $issue\n";
    }
    
    echo "\n🔧 ¿Quieres corregir estos problemas? (S/N): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    $answer = trim(strtolower($line));
    fclose($handle);
    
    if ($answer === 's' || $answer === 'si' || $answer === 'y' || $answer === 'yes') {
        echo "\n🔧 Corrigiendo problemas...\n\n";
        
        // Corregir user_mode
        if ($worker->user_mode !== 'socio' && $worker->user_mode !== null) {
            $worker->user_mode = 'socio';
            echo "✅ user_mode establecido a 'socio'\n";
        }
        
        // Corregir availability_status si es necesario
        if (!in_array($worker->availability_status, ['active', 'intermediate'])) {
            echo "⚠️ availability_status es '{$worker->availability_status}'. ¿Cambiar a 'active'? (S/N): ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            $answer = trim(strtolower($line));
            fclose($handle);
            if ($answer === 's' || $answer === 'si' || $answer === 'y' || $answer === 'yes') {
                $worker->availability_status = 'active';
                echo "✅ availability_status establecido a 'active'\n";
            }
        }
        
        // Establecer ubicación si no tiene
        if (!$location || !$location->lat || !$location->lng) {
            echo "⚠️ No tiene ubicación. ¿Establecer ubicación por defecto? (S/N): ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            $answer = trim(strtolower($line));
            fclose($handle);
            if ($answer === 's' || $answer === 'si' || $answer === 'y' || $answer === 'yes') {
                // Ubicación por defecto: Renaico, Chile
                DB::statement("
                    UPDATE workers 
                    SET location = ST_SetSRID(ST_MakePoint(-72.5730, -37.6672), 4326)
                    WHERE id = ?
                ", [$worker->id]);
                echo "✅ Ubicación establecida (Renaico, Chile)\n";
            }
        }
        
        // Establecer category_id si no tiene
        if (!$worker->category_id) {
            echo "⚠️ No tiene category_id. ¿Asignar categoría por defecto? (S/N): ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            $answer = trim(strtolower($line));
            fclose($handle);
            if ($answer === 's' || $answer === 'si' || $answer === 'y' || $answer === 'yes') {
                // Obtener primera categoría activa
                $category = DB::table('categories')->where('is_active', true)->first();
                if ($category) {
                    $worker->category_id = $category->id;
                    echo "✅ category_id establecido a {$category->id} ({$category->display_name})\n";
                } else {
                    echo "❌ No hay categorías activas disponibles\n";
                }
            }
        }
        
        $worker->save();
        echo "\n✅ Cambios guardados\n";
    }
}

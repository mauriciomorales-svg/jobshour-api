<?php
// Script para configurar el worker de Comercial Isabel en Renaico con cualquier categoría
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Worker;
use App\Models\User;

$email = 'comercialisabel2020@gmail.com';

echo "🔧 Configurando worker para: $email\n\n";

$user = User::where('email', $email)->first();

if (!$user) {
    echo "❌ Usuario no encontrado\n";
    exit(1);
}

echo "✅ Usuario encontrado: {$user->name} (ID: {$user->id})\n";

$worker = Worker::where('user_id', $user->id)->first();

if (!$worker) {
    echo "⚠️ Worker no existe. Creando...\n";
    $worker = Worker::create([
        'user_id' => $user->id,
        'is_visible' => true,
        'availability_status' => 'active',
        'hourly_rate' => 10000,
        'user_mode' => 'socio',
    ]);
    echo "✅ Worker creado (ID: {$worker->id})\n";
} else {
    echo "✅ Worker existe (ID: {$worker->id})\n";
}

// Obtener primera categoría activa
$category = DB::table('categories')->where('is_active', true)->orderBy('id')->first();

if (!$category) {
    echo "❌ No hay categorías activas disponibles\n";
    exit(1);
}

echo "\n🔧 Configurando:\n";

// 1. Establecer ubicación en Renaico, Chile
DB::statement("
    UPDATE workers 
    SET location = ST_SetSRID(ST_MakePoint(-72.5730, -37.6672), 4326)
    WHERE id = ?
", [$worker->id]);
echo "✅ Ubicación establecida en Renaico, Chile (Lat: -37.6672, Lng: -72.5730)\n";

// 2. Establecer categoría
$worker->category_id = $category->id;
echo "✅ Categoría asignada: {$category->display_name} (ID: {$category->id})\n";

// 3. Asegurar que user_mode sea 'socio'
$worker->user_mode = 'socio';
echo "✅ user_mode establecido a 'socio'\n";

// 4. Asegurar que availability_status sea 'active'
$worker->availability_status = 'active';
echo "✅ availability_status establecido a 'active'\n";

// 5. Asegurar que is_visible sea true
$worker->is_visible = true;
echo "✅ is_visible establecido a true\n";

$worker->save();

echo "\n✅ ¡Configuración completada!\n";
echo "\n📋 Resumen:\n";
echo "   - Usuario: {$user->name} ({$email})\n";
echo "   - Worker ID: {$worker->id}\n";
echo "   - Ubicación: Renaico, Chile (-37.6672, -72.5730)\n";
echo "   - Categoría: {$category->display_name}\n";
echo "   - Estado: active\n";
echo "   - Modo: socio\n";
echo "   - Visible: sí\n";
echo "\n✅ El worker debería aparecer en el mapa ahora.\n";

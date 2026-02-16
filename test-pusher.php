<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Pusher\Pusher;

echo "=== PRUEBA DE CONEXIÓN PUSHER ===\n\n";

// Credenciales desde .env
$appId = env('PUSHER_APP_ID');
$key = env('PUSHER_APP_KEY');
$secret = env('PUSHER_APP_SECRET');
$cluster = env('PUSHER_APP_CLUSTER');
$host = env('PUSHER_HOST') ?: 'api-' . $cluster . '.pusher.com';

echo "Configuración:\n";
echo "- App ID: {$appId}\n";
echo "- Key: {$key}\n";
echo "- Secret: " . substr($secret, 0, 5) . "...\n";
echo "- Cluster: {$cluster}\n";
echo "- Host: {$host}\n\n";

try {
    $pusher = new Pusher(
        $key,
        $secret,
        $appId,
        [
            'cluster' => $cluster,
            'host' => $host,
            'port' => 443,
            'scheme' => 'https',
            'useTLS' => true,
        ]
    );

    // Probar conexión
    echo "Conectando a Pusher...\n";
    
    // Intentar un trigger simple
    $response = $pusher->trigger('test-channel', 'test-event', ['message' => 'Hola desde Jobshour']);
    
    if ($response === true) {
        echo "✅ ÉXITO: Conexión a Pusher funcionando correctamente\n";
        echo "   El evento fue enviado al canal 'test-channel'\n\n";
        exit(0);
    } else {
        echo "⚠️ WARNING: Pusher respondió pero con status diferente\n";
        var_dump($response);
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "   Tipo: " . get_class($e) . "\n";
    exit(1);
}

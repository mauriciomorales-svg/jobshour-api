<?php
/**
 * Script para crear demandas públicas con coordenadas válidas
 * Ejecutar: php database/seeders/fix_demandas.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "✅ Conectado\n";

$users = $pdo->query('SELECT id FROM users LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
if (empty($users)) {
    echo "❌ No hay usuarios\n";
    exit(1);
}

$centerLat = -37.67;
$centerLng = -72.57;
$demandas = [
    'Electricista para instalación',
    'Pintar sala 20m2',
    'Instalar lámpara',
    'Arreglar gotera',
    'Cortar pasto',
    'Ayuda mudanza',
    'Reparar puerta',
    'Instalar cortinas'
];

$inserted = 0;
foreach ($demandas as $desc) {
    $lat = $centerLat + (mt_rand(-100, 100) / 3000);
    $lng = $centerLng + (mt_rand(-100, 100) / 3000);
    $client = $users[array_rand($users)];
    $price = rand(15000, 80000);
    
    try {
        $pdo->prepare("
            INSERT INTO service_requests 
            (client_id, description, offered_price, type, category_type, status, 
             created_at, updated_at, client_location)
            VALUES (?, ?, ?, 'fixed_job', 'fixed', 'pending', NOW(), NOW(), 
                    ST_SetSRID(ST_MakePoint(?, ?), 4326))
        ")->execute([$client, $desc, $price, $lng, $lat]);
        $inserted++;
        echo "   ✅ $desc - ($lat, $lng)\n";
    } catch (Exception $e) {
        echo "   ❌ " . $e->getMessage() . "\n";
    }
}

echo "\n🎉 Creadas $inserted demandas\n";

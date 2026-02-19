<?php
require __DIR__ . '/vendor/autoload.php';
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

echo "🧹 Limpiando datos...\n";
$pdo->query("DELETE FROM personal_access_tokens");
$pdo->query("DELETE FROM service_requests WHERE worker_id IS NULL");
$pdo->query("DELETE FROM users WHERE id > 10");
echo "✅ Datos limpios\n";

// Crear usuarios de prueba con passwords simples
$users = [
    ['id' => 11, 'name' => 'Usuario Test 1', 'email' => 'test1@local.cl', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'],
    ['id' => 12, 'name' => 'Usuario Test 2', 'email' => 'test2@local.cl', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'],
];

foreach ($users as $u) {
    $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())")->execute([$u['id'], $u['name'], $u['email'], $u['password']]);
    $pdo->prepare("INSERT INTO workers (user_id, availability_status, created_at, updated_at) VALUES (?, 'intermediate', NOW(), NOW())")->execute([$u['id']]);
    echo "✅ User {$u['id']} + Worker creado\n";
}

// Crear demandas
$demandas = [
    'Electricista urgente',
    'Pintar habitación',
    'Instalar lámpara',
    'Arreglar gotera',
    'Cortar pasto',
];

$centerLat = -37.67;
$centerLng = -72.57;

foreach ($demandas as $i => $desc) {
    $lat = $centerLat + (mt_rand(-100, 100) / 3000);
    $lng = $centerLng + (mt_rand(-100, 100) / 3000);
    $clientId = 11;
    $price = rand(15000, 50000);
    
    $pdo->prepare("
        INSERT INTO service_requests (client_id, description, offered_price, type, category_type, status, created_at, updated_at, client_location)
        VALUES (?, ?, ?, 'fixed_job', 'fixed', 'pending', NOW(), NOW(), ST_SetSRID(ST_MakePoint(?, ?), 4326))
    ")->execute([$clientId, $desc, $price, $lng, $lat]);
}
echo "✅ " . count($demandas) . " demandas creadas\n";

// Crear token para user 11
$tokenId = 100;
$tokenPlain = 'local_token_' . bin2hex(random_bytes(8));
$hashed = hash('sha256', $tokenPlain);
$pdo->prepare("INSERT INTO personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities, created_at, updated_at) VALUES (?, 'App\\Models\\User', 11, 'local', ?, '[\"*\"]', NOW(), NOW())")->execute([$tokenId, $hashed]);

echo "\n🎯 TOKEN PARA USAR:\n";
echo "localStorage.setItem('auth_token', '$tokenId|$tokenPlain')\n";

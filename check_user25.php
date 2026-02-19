<?php
require __DIR__ . '/vendor/autoload.php';
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Verificar user 25
$u = $pdo->prepare('SELECT id, name, email FROM users WHERE id = 25');
$u->execute();
$user = $u->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "❌ User 25 no existe\n";
    echo "Creando usuario de prueba...\n";
    try {
        $pdo->prepare("INSERT INTO users (id, name, email, created_at, updated_at) VALUES (25, 'Test User', 'test25@test.cl', NOW(), NOW())")->execute();
        echo "✅ User 25 creado\n";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit;
    }
} else {
    echo "✅ User 25: {$user['name']} ({$user['email']})\n";
}

// Verificar worker
$w = $pdo->prepare('SELECT id, availability_status FROM workers WHERE user_id = 25');
$w->execute();
$worker = $w->fetch(PDO::FETCH_ASSOC);

if (!$worker) {
    echo "⚠️  Sin worker. Creando...\n";
    $pdo->prepare("INSERT INTO workers (user_id, availability_status, is_verified, created_at, updated_at) VALUES (25, 'intermediate', false, NOW(), NOW())")->execute();
    echo "✅ Worker creado para user 25\n";
} else {
    echo "✅ Worker: ID {$worker['id']}, status: {$worker['availability_status']}\n";
}

// Ver demandas existentes
echo "\n📋 Demandas disponibles:\n";
$d = $pdo->query("SELECT id, description, status, client_id, worker_id FROM service_requests WHERE worker_id IS NULL AND status = 'pending' LIMIT 5");
foreach ($d as $row) {
    echo "   ID {$row['id']}: {$row['description']}\n";
}

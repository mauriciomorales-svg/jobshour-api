<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Ver categorías disponibles
$cat = $pdo->query("SELECT id FROM categories LIMIT 1")->fetch();
$catId = $cat ? $cat['id'] : null;

// Buscar o crear cliente con ID para la demanda
$client = $pdo->query("SELECT id FROM users LIMIT 1")->fetch();
$clientId = $client['id'];

// Crear worker para User 31 si no existe
$u31 = $pdo->query("SELECT id FROM users WHERE id = 31")->fetch();
if (!$u31) {
    $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (31, 'Google User 31', 'user31@test.local', 'password', NOW(), NOW())")->execute();
    echo "✅ User 31 creado\n";
}
$w31 = $pdo->query("SELECT id FROM workers WHERE user_id = 31")->fetch();
if (!$w31) {
    $pdo->prepare("INSERT INTO workers (user_id, availability_status, created_at, updated_at) VALUES (31, 'intermediate', NOW(), NOW())")->execute();
    echo "✅ Worker para User 31 creado\n";
} else {
    echo "✅ Worker User 31 ya existe\n";
}

// Crear demanda ID 10 si no existe
$d10 = $pdo->query("SELECT id FROM service_requests WHERE id = 10")->fetch();
if (!$d10) {
    $pdo->prepare("
        INSERT INTO service_requests 
        (id, client_id, category_id, description, status, urgency, offered_price, category_type, type, created_at, updated_at)
        VALUES 
        (10, ?, ?, 'Limpieza profunda para Camila Navarro', 'pending', 'urgent', 42792, 'fixed', 'fixed_job', NOW(), NOW())
    ")->execute([$clientId, $catId]);
    echo "✅ Demanda ID 10 creada en BD local\n";
} else {
    // Reset a pending
    $pdo->prepare("UPDATE service_requests SET status='pending', worker_id=NULL WHERE id=10")->execute();
    echo "✅ Demanda ID 10 reseteada a pending\n";
}

// Reset todas las demandas de prueba disponibles
$demandas = $pdo->query("SELECT id FROM service_requests WHERE status='pending' AND worker_id IS NULL")->fetchAll();
echo "\n=== DEMANDAS DISPONIBLES ===\n";
foreach ($demandas as $d) echo "ID {$d['id']}\n";

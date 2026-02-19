<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');

// Verificar token 31
echo "=== TOKEN ID 31 ===\n";
$t = $pdo->query("SELECT id, tokenable_id FROM personal_access_tokens WHERE id = 31")->fetch();
if ($t) {
    $userId = $t['tokenable_id'];
    echo "Token 31 -> User {$userId}\n";
    
    // Verificar worker
    $w = $pdo->query("SELECT id, availability_status FROM workers WHERE user_id = {$userId}")->fetch();
    if ($w) {
        echo "✅ Worker ID={$w['id']} status={$w['availability_status']}\n";
        if ($w['availability_status'] === 'inactive') {
            $pdo->prepare("UPDATE workers SET availability_status='intermediate' WHERE user_id=?")->execute([$userId]);
            echo "✅ Worker activado a 'intermediate'\n";
        }
    } else {
        $pdo->prepare("INSERT INTO workers (user_id, availability_status, created_at, updated_at) VALUES (?, 'intermediate', NOW(), NOW())")->execute([$userId]);
        echo "✅ Worker creado para User {$userId}\n";
    }
} else {
    echo "❌ Token 31 no existe\n";
    $tokens = $pdo->query("SELECT id, tokenable_id FROM personal_access_tokens ORDER BY id DESC LIMIT 5")->fetchAll();
    foreach ($tokens as $tk) echo "  Token {$tk['id']} -> User {$tk['tokenable_id']}\n";
}

// Verificar demanda 10
echo "\n=== DEMANDA ID 10 ===\n";
$d = $pdo->query("SELECT id, status, worker_id FROM service_requests WHERE id = 10")->fetch();
if ($d) {
    echo "status={$d['status']} worker_id=" . ($d['worker_id'] ?? 'NULL') . "\n";
    if ($d['status'] !== 'pending' || $d['worker_id']) {
        $pdo->query("UPDATE service_requests SET status='pending', worker_id=NULL WHERE id=10");
        echo "✅ Demanda 10 reseteada a pending\n";
    } else {
        echo "✅ Ya está pending y disponible\n";
    }
} else {
    echo "❌ No existe — creando...\n";
    $cat = $pdo->query("SELECT id FROM categories LIMIT 1")->fetch();
    $client = $pdo->query("SELECT id FROM users WHERE id != " . ($t['tokenable_id'] ?? 999) . " LIMIT 1")->fetch();
    $pdo->prepare("INSERT INTO service_requests (id, client_id, category_id, description, status, urgency, offered_price, category_type, type, created_at, updated_at) VALUES (10, ?, ?, 'Limpieza profunda para Camila Navarro', 'pending', 'urgent', 42792, 'fixed', 'fixed_job', NOW(), NOW())")->execute([$client['id'], $cat['id'] ?? null]);
    echo "✅ Demanda 10 creada\n";
}

echo "\n🎉 LISTO\n";

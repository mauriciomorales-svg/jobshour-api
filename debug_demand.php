<?php
require __DIR__ . '/vendor/autoload.php';
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Ver estado de la demanda ID 10
$d = $pdo->query("SELECT id, description, status, worker_id, client_id, pin_expires_at, type FROM service_requests WHERE id = 10")->fetch(PDO::FETCH_ASSOC);
echo "📋 Demanda ID 10:\n";
print_r($d);

// Ver si el usuario logueado tiene token válido
echo "\n👤 Token ID 21 (del log anterior):\n";
$t = $pdo->query("SELECT tokenable_id, name FROM personal_access_tokens WHERE id = 21")->fetch(PDO::FETCH_ASSOC);
if ($t) {
    echo "   User ID: {$t['tokenable_id']}\n";
    $u = $pdo->query("SELECT id, name FROM users WHERE id = {$t['tokenable_id']}")->fetch(PDO::FETCH_ASSOC);
    echo "   Nombre: {$u['name']}\n";
    
    $w = $pdo->query("SELECT id, availability_status FROM workers WHERE user_id = {$t['tokenable_id']}")->fetch(PDO::FETCH_ASSOC);
    if ($w) {
        echo "   Worker: ID {$w['id']}, status: {$w['availability_status']}\n";
    } else {
        echo "   ❌ NO TIENE WORKER\n";
    }
} else {
    echo "   ❌ Token 21 no existe\n";
}

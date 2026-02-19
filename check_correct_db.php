<?php
// LA BD CORRECTA es puerto 5434, no 5432!
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'postgres', 'postgres');

echo "=== TOKEN ID 31 ===\n";
$t = $pdo->query("SELECT id, tokenable_id FROM personal_access_tokens WHERE id = 31")->fetch();
if ($t) {
    echo "Token 31 -> User {$t['tokenable_id']}\n";
    $w = $pdo->query("SELECT id, availability_status FROM workers WHERE user_id = {$t['tokenable_id']}")->fetch();
    echo $w ? "✅ Worker: {$w['id']} ({$w['availability_status']})\n" : "❌ SIN WORKER\n";
} else {
    echo "❌ Token 31 no existe\n";
    $tokens = $pdo->query("SELECT id, tokenable_id FROM personal_access_tokens ORDER BY id DESC LIMIT 5")->fetchAll();
    foreach ($tokens as $tk) echo "  Token {$tk['id']} -> User {$tk['tokenable_id']}\n";
}

echo "\n=== DEMANDA ID 10 ===\n";
$d = $pdo->query("SELECT id, status, worker_id FROM service_requests WHERE id = 10")->fetch();
echo $d ? "status={$d['status']} worker_id={$d['worker_id']}\n" : "❌ No existe\n";

echo "\n=== DEMANDAS DISPONIBLES (pending, sin worker) ===\n";
$list = $pdo->query("SELECT id, status, worker_id FROM service_requests WHERE status='pending' AND worker_id IS NULL LIMIT 5")->fetchAll();
foreach ($list as $r) echo "ID {$r['id']}: pending\n";

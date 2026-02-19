<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

echo "=== TOKENS ===\n";
$tokens = $pdo->query('SELECT id, tokenable_id FROM personal_access_tokens ORDER BY id DESC LIMIT 5')->fetchAll();
foreach ($tokens as $t) {
    echo "Token {$t['id']} -> User {$t['tokenable_id']}\n";
}

echo "\n=== WORKERS ===\n";
$workers = $pdo->query('SELECT w.id, w.user_id, u.name, w.availability_status FROM workers w JOIN users u ON w.user_id = u.id')->fetchAll();
foreach ($workers as $w) {
    echo "Worker {$w['id']} -> User {$w['user_id']} ({$w['name']}) [{$w['availability_status']}]\n";
}

echo "\n=== DEMANDAS PENDIENTES ===\n";
$demandas = $pdo->query("SELECT id, description FROM service_requests WHERE status = 'pending' AND worker_id IS NULL LIMIT 3")->fetchAll();
foreach ($demandas as $d) {
    echo "Demanda {$d['id']}: {$d['description']}\n";
}

<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');

// Ver estado de todas las demandas que el feed muestra
echo "=== TODAS LAS service_requests ===\n";
$all = $pdo->query("SELECT id, status, worker_id, client_id FROM service_requests ORDER BY id")->fetchAll();
foreach ($all as $r) {
    echo "ID {$r['id']}: status={$r['status']} worker_id=" . ($r['worker_id'] ?? 'NULL') . " client={$r['client_id']}\n";
}

// Resetear TODAS las demandas a pending para poder probar
echo "\n=== RESETEANDO demandas con worker_id (status=pending pero tomadas) ===\n";
$reset = $pdo->exec("UPDATE service_requests SET worker_id=NULL WHERE status='pending' AND worker_id IS NOT NULL");
echo "Reseteadas: {$reset}\n";

// También resetear las que tienen status != pending
$reset2 = $pdo->exec("UPDATE service_requests SET status='pending', worker_id=NULL WHERE status NOT IN ('pending', 'completed', 'cancelled')");
echo "Otras reseteadas: {$reset2}\n";

// Verificar User 21 (el que usa el token 31)
echo "\n=== USER 21 ===\n";
$w = $pdo->query("SELECT id, availability_status FROM workers WHERE user_id = 21")->fetch();
echo $w ? "Worker ID={$w['id']} status={$w['availability_status']}\n" : "❌ SIN WORKER\n";

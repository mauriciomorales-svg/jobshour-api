<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Listar columnas de service_requests
echo "=== COLUMNAS DE service_requests ===\n";
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'service_requests' ORDER BY ordinal_position")->fetchAll();
foreach ($cols as $c) echo "  " . $c['column_name'] . "\n";

// Verificar si demanda 10 existe sin columnas geométricas
echo "\n=== DEMANDA ID 10 (sin geo) ===\n";
$d = $pdo->query("SELECT id, description, status, worker_id FROM service_requests WHERE id = 10")->fetch();
if ($d) {
    echo "✅ EXISTE: status={$d['status']} worker_id=" . ($d['worker_id'] ?? 'NULL') . "\n";
    echo "   Desc: {$d['description']}\n";
} else {
    echo "❌ No existe\n";
}

// Ver las primeras 5 demandas disponibles
echo "\n=== PRIMERAS 5 DEMANDAS ===\n";
$list = $pdo->query("SELECT id, status, worker_id FROM service_requests ORDER BY id LIMIT 5")->fetchAll();
foreach ($list as $r) {
    echo "ID {$r['id']}: status={$r['status']} worker={$r['worker_id']}\n";
}

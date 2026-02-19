<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Simular query del endpoint /api/v1/demand/nearby
$lat = -37.67;
$lng = -72.57;

$demands = $pdo->query("SELECT id, description, status, worker_id, client_id, ST_X(pos::geometry) as lng, ST_Y(pos::geometry) as lat FROM service_requests WHERE status = 'pending' AND worker_id IS NULL LIMIT 10")->fetchAll();

echo "=== DEMANDAS EN BD (status='pending', worker_id IS NULL) ===\n";
foreach ($demands as $d) {
    echo "ID {$d['id']}: {$d['description']}\n";
}

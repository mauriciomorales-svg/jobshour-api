<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
$rows = $pdo->query("SELECT id, status, worker_id, ST_AsText(client_location) as loc FROM service_requests WHERE status='pending' AND worker_id IS NULL ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    echo "ID {$r['id']}: loc=" . ($r['loc'] ?? 'NULL') . "\n";
}
// Also check seeded demands with location
$all = $pdo->query("SELECT id, ST_AsText(client_location) as loc FROM service_requests WHERE client_location IS NOT NULL LIMIT 5")->fetchAll();
echo "\n=== CON LOCATION ===\n";
foreach ($all as $r) echo "ID {$r['id']}: {$r['loc']}\n";
if (empty($all)) echo "NINGUNA tiene client_location\n";

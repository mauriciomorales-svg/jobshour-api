<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');

// Delete test requests created by our scripts (101, 102+)
$deleted = $pdo->exec("DELETE FROM service_requests WHERE id > 100");
echo "Deleted {$deleted} test requests\n";

// Reset demand 10 to pending
$pdo->exec("UPDATE service_requests SET status='pending', worker_id=NULL WHERE id=10");
echo "Demand 10 reset to pending\n";

// Verify
$d = $pdo->query("SELECT id, status, worker_id FROM service_requests WHERE id=10")->fetch();
echo "Demand 10: status={$d['status']} worker_id=" . ($d['worker_id'] ?? 'NULL') . "\n";

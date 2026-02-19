<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='workers' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo "Columns: " . implode(', ', $cols) . "\n\n";

// Check status field
$rows = $pdo->query("SELECT availability_status, COUNT(*) as cnt FROM workers GROUP BY availability_status ORDER BY availability_status")->fetchAll(PDO::FETCH_ASSOC);
echo "=== Por availability_status ===\n";
foreach ($rows as $r) echo "{$r['availability_status']}: {$r['cnt']}\n";

<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');

// Set 3 workers to intermediate (amarillo)
$stmt = $pdo->prepare("UPDATE workers SET availability_status = 'intermediate' WHERE id = ?");
foreach ([3, 5, 7] as $id) {
    $stmt->execute([$id]);
    echo "Worker $id -> intermediate\n";
}

// Verify
$rows = $pdo->query("SELECT availability_status, COUNT(*) as cnt FROM workers GROUP BY availability_status ORDER BY availability_status")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Resultado ===\n";
foreach ($rows as $r) echo "{$r['availability_status']}: {$r['cnt']}\n";

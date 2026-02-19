<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
$rows = $pdo->query("SELECT id, pickup_address, delivery_address FROM service_requests WHERE status='pending' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "ID {$r['id']}: pickup={$r['pickup_address']} | delivery={$r['delivery_address']}\n";
}

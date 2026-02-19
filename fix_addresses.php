<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');

$addresses = [
    10 => 'Av. Arturo Prat 450, Renaico',
    22 => 'Calle Comercio 123, Renaico',
    43 => 'Pasaje Los Aromos 78, Renaico',
    71 => 'Camino Quillota Km 3, Renaico',
    98 => 'Villa Los Héroes 210, Renaico',
];

$stmt = $pdo->prepare("UPDATE service_requests SET pickup_address = ? WHERE id = ?");
foreach ($addresses as $id => $addr) {
    $stmt->execute([$addr, $id]);
    echo "ID $id -> $addr\n";
}

// Also update all requests created by take_demand.php that have no address
$pdo->exec("UPDATE service_requests SET pickup_address = 'Renaico, Biobío' WHERE pickup_address IS NULL OR pickup_address = ''");
echo "\nDone. Updated remaining rows with default address.\n";

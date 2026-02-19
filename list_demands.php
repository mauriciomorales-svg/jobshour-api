<?php
require __DIR__ . '/vendor/autoload.php';
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Ver todas las demandas
$d = $pdo->query("SELECT id, description, status, client_id, worker_id, type FROM service_requests WHERE status = 'pending' LIMIT 10");
echo "📋 Demandas disponibles en BD local:\n";
foreach ($d as $row) {
    echo "   ID {$row['id']}: {$row['description']} (type: {$row['type']}, worker_id: " . ($row['worker_id'] ?? 'NULL') . ")\n";
}

echo "\n🎯 La demanda ID 10 probablemente NO existe en tu BD local.\n";
echo "Intenta con una de estas IDs cuando hagas click en 'Tomar Solicitud'\n";

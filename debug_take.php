<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Verificar demanda ID 10
$d = $pdo->query('SELECT id, description, status, worker_id, client_id, pin_expires_at FROM service_requests WHERE id = 10')->fetch(PDO::FETCH_ASSOC);
if ($d) {
    echo "=== DEMANDA ID 10 ===\n";
    echo "Descripción: {$d['description']}\n";
    echo "Status: {$d['status']}\n";
    echo "Worker asignado: " . ($d['worker_id'] ?: 'Ninguno') . "\n";
    echo "Client ID: {$d['client_id']}\n";
    echo "PIN expira: {$d['pin_expires_at']}\n";
} else {
    echo "❌ Demanda 10 no existe\n";
}

// Verificar worker de User 30
$w = $pdo->query('SELECT id, availability_status, updated_at FROM workers WHERE user_id = 30')->fetch(PDO::FETCH_ASSOC);
if ($w) {
    echo "\n=== WORKER USER 30 ===\n";
    echo "Worker ID: {$w['id']}\n";
    echo "Status: {$w['availability_status']}\n";
    echo "Updated: {$w['updated_at']}\n";
} else {
    echo "\n❌ User 30 no tiene worker\n";
}

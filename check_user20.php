<?php
require __DIR__ . '/vendor/autoload.php';
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Verificar si user 20 existe
$u = $pdo->prepare('SELECT id, name, email FROM users WHERE id = 20');
$u->execute();
$user = $u->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "❌ User 20 NO existe en la BD\n";
    echo "Usuarios disponibles (con workers):\n";
    $workers = $pdo->query('SELECT w.user_id, u.name, w.availability_status FROM workers w JOIN users u ON w.user_id = u.id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($workers as $w) {
        echo "  - User {$w['user_id']}: {$w['name']} (status: {$w['availability_status']})\n";
    }
    exit(1);
}

echo "✅ User 20 existe: {$user['name']}\n";

// Verificar si tiene worker
$w = $pdo->prepare('SELECT id, availability_status FROM workers WHERE user_id = 20');
$w->execute();
$worker = $w->fetch(PDO::FETCH_ASSOC);

if (!$worker) {
    echo "⚠️  User 20 no tiene worker. Creando...\n";
    try {
        $pdo->prepare("INSERT INTO workers (user_id, availability_status, is_verified, created_at, updated_at) VALUES (20, 'intermediate', false, NOW(), NOW())")->execute();
        echo "✅ Worker creado para user 20\n";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "✅ User 20 tiene worker (ID {$worker['id']}, status: {$worker['availability_status']})\n";
}

<?php
require __DIR__ . '/vendor/autoload.php';
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Ver usuarios
$u = $pdo->query('SELECT id, name FROM users ORDER BY id LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
echo "Usuarios:\n";
foreach ($u as $user) {
    echo "  ID {$user['id']}: {$user['name']}\n";
}

// Crear workers faltantes
foreach ($u as $user) {
    $hasWorker = $pdo->prepare('SELECT COUNT(*) FROM workers WHERE user_id = ?');
    $hasWorker->execute([$user['id']]);
    if ($hasWorker->fetchColumn() == 0) {
        echo "\nCreando worker para user {$user['id']}...\n";
        try {
            $pdo->prepare("INSERT INTO workers (user_id, availability_status, is_verified, created_at, updated_at) VALUES (?, 'intermediate', false, NOW(), NOW())")->execute([$user['id']]);
            echo "  ✅ Worker creado\n";
        } catch (Exception $e) {
            echo "  ❌ " . $e->getMessage() . "\n";
        }
    }
}
echo "\n✅ Listo\n";

<?php
require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

echo "🔍 Verificando tokens en BD local...\n";
$tokens = $pdo->query('SELECT id, tokenable_id, name FROM personal_access_tokens LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
if (empty($tokens)) {
    echo "⚠️  No hay tokens en BD local\n";
} else {
    foreach ($tokens as $t) {
        echo "   Token {$t['id']}: user_id={$t['tokenable_id']}, name={$t['name']}\n";
    }
}

echo "\n✅ Asegurando que todos los usuarios tengan workers...\n";
$users = $pdo->query('SELECT id, name FROM users ORDER BY id LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $user) {
    $hasWorker = $pdo->prepare('SELECT COUNT(*) FROM workers WHERE user_id = ?');
    $hasWorker->execute([$user['id']]);
    if ($hasWorker->fetchColumn() == 0) {
        echo "   Creando worker para user {$user['id']} ({$user['name']})...\n";
        try {
            $pdo->prepare("INSERT INTO workers (user_id, availability_status, is_verified, created_at, updated_at) VALUES (?, 'intermediate', false, NOW(), NOW())")->execute([$user['id']]);
            echo "   ✅ Worker creado\n";
        } catch (Exception $e) {
            echo "   ❌ {$e->getMessage()}\n";
        }
    } else {
        echo "   ✅ User {$user['id']} ya tiene worker\n";
    }
}

echo "\n🎉 Listo. Usa cualquiera de estos usuarios para loguearte localmente.\n";

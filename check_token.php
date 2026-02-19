<?php
require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Token completo: 21|lNpLCV1539K16LJmnYSxkBJhvVAJAV6ssO4Nwdou2f47edcb
$tokenId = 21; // La parte antes del |
$token = 'lNpLCV1539K16LJmnYSxkBJhvVAJAV6ssO4Nwdou2f47edcb';

// Buscar el token en personal_access_tokens
$stmt = $pdo->prepare('SELECT tokenable_type, tokenable_id FROM personal_access_tokens WHERE id = ?');
$stmt->execute([$tokenId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "❌ Token ID $tokenId no encontrado\n";
    exit(1);
}

echo "✅ Token encontrado\n";
echo "   Tokenable Type: {$row['tokenable_type']}\n";
echo "   Tokenable ID: {$row['tokenable_id']}\n";

// Buscar el usuario
$userStmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ?');
$userStmt->execute([$row['tokenable_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "❌ Usuario no encontrado\n";
    exit(1);
}

echo "\n✅ Usuario: {$user['name']} (ID: {$user['id']})\n";

// Verificar si tiene worker
$workerStmt = $pdo->prepare('SELECT id, availability_status FROM workers WHERE user_id = ?');
$workerStmt->execute([$user['id']]);
$worker = $workerStmt->fetch(PDO::FETCH_ASSOC);

if ($worker) {
    echo "✅ Worker: ID {$worker['id']}, status: {$worker['availability_status']}\n";
} else {
    echo "⚠️  NO tiene worker. Creando...\n";
    try {
        $pdo->prepare("INSERT INTO workers (user_id, availability_status, is_verified, created_at, updated_at) VALUES (?, 'intermediate', false, NOW(), NOW())")->execute([$user['id']]);
        echo "✅ Worker creado con status 'intermediate'\n";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

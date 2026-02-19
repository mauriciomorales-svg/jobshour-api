<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// El token 31|LLL... significa token ID=31, no User ID=31
// En Sanctum: token_id|token_value
echo "=== TOKEN ID 31 ===\n";
$t = $pdo->query("SELECT id, tokenable_id, tokenable_type, name FROM personal_access_tokens WHERE id = 31")->fetch();
if ($t) {
    echo "Token ID: {$t['id']}\n";
    echo "User ID (tokenable_id): {$t['tokenable_id']}\n";
    
    // Verificar si ese usuario tiene worker
    $w = $pdo->query("SELECT id, availability_status FROM workers WHERE user_id = {$t['tokenable_id']}")->fetch();
    if ($w) {
        echo "✅ Worker: ID={$w['id']} status={$w['availability_status']}\n";
    } else {
        echo "❌ SIN WORKER\n";
        // Crear worker
        $pdo->prepare("INSERT INTO workers (user_id, availability_status, created_at, updated_at) VALUES (?, 'intermediate', NOW(), NOW())")->execute([$t['tokenable_id']]);
        echo "✅ Worker creado para User {$t['tokenable_id']}\n";
    }
} else {
    echo "❌ Token 31 no existe en BD local\n";
    // Listar últimos tokens
    $tokens = $pdo->query("SELECT id, tokenable_id FROM personal_access_tokens ORDER BY id DESC LIMIT 5")->fetchAll();
    echo "\nÚltimos tokens:\n";
    foreach ($tokens as $tk) echo "  Token ID {$tk['id']} -> User {$tk['tokenable_id']}\n";
}

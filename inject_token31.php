<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// El token del usuario actual es: 31|LLLbxAPw53Hmp89i05jjrDNOeD10vk0dYZvXs09V265c5b34
// Sanctum almacena SHA256 del token_value (la parte después del |)
$tokenId = 31;
$tokenValue = 'LLLbxAPw53Hmp89i05jjrDNOeD10vk0dYZvXs09V265c5b34';
$tokenHash = hash('sha256', $tokenValue);

// Verificar que User 31 existe (creado en fix_all.php)
$u = $pdo->query("SELECT id FROM users WHERE id = 31")->fetch();
if (!$u) {
    $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (31, 'Google User', 'googleuser31@test.local', 'password', NOW(), NOW())")->execute();
    echo "✅ User 31 creado\n";
}

// Verificar worker
$w = $pdo->query("SELECT id FROM workers WHERE user_id = 31")->fetch();
if (!$w) {
    $pdo->prepare("INSERT INTO workers (user_id, availability_status, created_at, updated_at) VALUES (31, 'intermediate', NOW(), NOW())")->execute();
    echo "✅ Worker creado para User 31\n";
}

// Borrar token ID 31 si existe
$pdo->query("DELETE FROM personal_access_tokens WHERE id = 31");

// Insertar el token con el hash correcto
$pdo->prepare("
    INSERT INTO personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities, created_at, updated_at)
    VALUES (?, 'App\\Models\\User', 31, 'social-login', ?, '[\"*\"]', NOW(), NOW())
")->execute([$tokenId, $tokenHash]);

echo "✅ Token 31 creado en BD local -> User 31\n";

// Verificar demanda 10
$d = $pdo->query("SELECT id, status, worker_id FROM service_requests WHERE id = 10")->fetch();
if ($d) {
    if ($d['status'] !== 'pending' || $d['worker_id']) {
        $pdo->query("UPDATE service_requests SET status='pending', worker_id=NULL WHERE id=10");
        echo "✅ Demanda 10 reseteada a pending\n";
    } else {
        echo "✅ Demanda 10 ya está pendiente\n";
    }
} else {
    $cat = $pdo->query("SELECT id FROM categories LIMIT 1")->fetch();
    $pdo->prepare("INSERT INTO service_requests (id, client_id, category_id, description, status, urgency, offered_price, category_type, type, created_at, updated_at) VALUES (10, 2, ?, 'Limpieza profunda para Camila Navarro', 'pending', 'urgent', 42792, 'fixed', 'fixed_job', NOW(), NOW())")->execute([$cat['id'] ?? null]);
    echo "✅ Demanda 10 creada\n";
}

echo "\n🎉 LISTO. Prueba ahora 'Tomar esta solicitud'\n";
echo "   User 31 tiene Worker y Token válido en BD local.\n";

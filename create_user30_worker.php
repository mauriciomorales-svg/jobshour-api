<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');

// Verificar si user 30 existe
$u = $pdo->query('SELECT id, name FROM users WHERE id = 30')->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    // Crear user 30
    $pdo->prepare("INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (30, 'Usuario Google', 'user30@test.com', 'password', NOW(), NOW())")->execute();
    echo "✅ User 30 creado\n";
}

// Crear worker para user 30
$pdo->prepare("INSERT INTO workers (user_id, availability_status, created_at, updated_at) VALUES (30, 'intermediate', NOW(), NOW())")->execute();
echo "✅ Worker creado para User 30\n";
echo "\n🎉 Ahora puedes tomar demandas con este usuario.\n";

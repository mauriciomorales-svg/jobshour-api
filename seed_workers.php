<?php
// Seed workers de prueba para Renaico
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$workers = [
    ['Carlos Soto', 'carlos.soto@demo.cl', 'ElMaestro', 14, -37.665, -72.571, 'active', 12000],
    ['Maria Lopez', 'maria.lopez@demo.cl', 'LaChispa', 14, -37.670, -72.580, 'active', 15000],
    ['Pedro Munoz', 'pedro.munoz@demo.cl', 'ElPintor', 16, -37.660, -72.565, 'intermediate', 18000],
    ['Ana Fuentes', 'ana.fuentes@demo.cl', 'BrilloTotal', 19, -37.672, -72.575, 'active', 10000],
    ['Juan Reyes', 'juan.reyes@demo.cl', 'ManosDeMadera', 17, -37.658, -72.560, 'intermediate', 20000],
    ['Rosa Diaz', 'rosa.diaz@demo.cl', 'LaVecinaPro', 18, -37.675, -72.585, 'active', 8000],
    ['Luis Herrera', 'luis.herrera@demo.cl', 'CerrajeroTop', 21, -37.663, -72.568, 'active', 25000],
    ['Carmen Vega', 'carmen.vega@demo.cl', 'HiloDeOro', 22, -37.668, -72.578, 'intermediate', 14000],
    ['Diego Mora', 'diego.mora@demo.cl', 'ElMensajero', 24, -37.671, -72.582, 'active', 9000],
    ['Patricia Silva', 'patricia.silva@demo.cl', 'PatiLove', 23, -37.656, -72.555, 'active', 11000],
];

foreach ($workers as $w) {
    [$name, $email, $nick, $catId, $lat, $lng, $status, $rate] = $w;
    
    // Insert user
    $pdo->exec("INSERT INTO users (name, email, password, nickname, type, is_active, created_at, updated_at) 
        VALUES ('$name', '$email', 'dummy', '$nick', 'worker', true, NOW(), NOW()) ON CONFLICT (email) DO NOTHING");
    
    $uid = $pdo->query("SELECT id FROM users WHERE email='$email'")->fetchColumn();
    
    // Upsert worker
    $exists = $pdo->query("SELECT id FROM workers WHERE user_id=$uid")->fetchColumn();
    if ($exists) {
        $pdo->exec("UPDATE workers SET location=ST_SetSRID(ST_MakePoint($lng, $lat), 4326), availability_status='$status', category_id=$catId, hourly_rate=$rate, last_seen_at=NOW() WHERE user_id=$uid");
    } else {
        $pdo->exec("INSERT INTO workers (user_id, category_id, hourly_rate, availability_status, location, last_seen_at, created_at, updated_at)
            VALUES ($uid, $catId, $rate, '$status', ST_SetSRID(ST_MakePoint($lng, $lat), 4326), NOW(), NOW(), NOW())");
    }
    
    echo "✅ $name (@$nick) - $status\n";
}

// Actualizar Mauricio Morales con ubicación
$pdo->exec("UPDATE workers SET location=ST_SetSRID(ST_MakePoint(-72.573, -37.6672), 4326), last_seen_at=NOW() WHERE id=25");
echo "✅ Mauricio Morales - ubicación actualizada\n";

echo "\nDone! " . count($workers) . " workers + 1 actualizado\n";

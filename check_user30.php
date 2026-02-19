<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');
$w = $pdo->query('SELECT id, availability_status FROM workers WHERE user_id = 30')->fetch(PDO::FETCH_ASSOC);
if ($w) {
    echo "✅ Worker encontrado: ID {$w['id']} - status: {$w['availability_status']}\n";
} else {
    echo "❌ User 30 NO tiene Worker\n";
}

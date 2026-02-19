<?php
$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=jobshour', 'postgres', 'postgres');
$pdo->prepare("UPDATE workers SET availability_status = 'intermediate' WHERE user_id = 1")->execute();
echo "✅ Worker de Mauricio Morales (user 1) activado como 'intermediate'\n";

<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
$cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='service_requests' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "{$c['column_name']}: {$c['data_type']}\n";

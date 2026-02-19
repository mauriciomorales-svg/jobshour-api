<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
$r = $pdo->query("SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conname LIKE '%urgency%'");
foreach ($r as $row) echo $row['conname'] . ' = ' . $row['pg_get_constraintdef'] . PHP_EOL;

// Also check dashboard feed route
$r2 = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='service_requests' AND column_name='urgency'");
echo "urgency column exists: " . ($r2->rowCount() > 0 ? 'yes' : 'no') . PHP_EOL;

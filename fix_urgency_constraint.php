<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
$pdo->exec("ALTER TABLE service_requests DROP CONSTRAINT IF EXISTS service_requests_urgency_check");
$pdo->exec("ALTER TABLE service_requests ADD CONSTRAINT service_requests_urgency_check CHECK (urgency IN ('low','medium','high','urgent','normal'))");
echo "Constraint updated\n";
$r = $pdo->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'service_requests_urgency_check'");
echo $r->fetchColumn() . "\n";

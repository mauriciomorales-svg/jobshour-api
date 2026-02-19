<?php
$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
echo "DB OK\n";
$r = $pdo->query("SELECT PostGIS_Version()");
echo "PostGIS: " . $r->fetchColumn() . "\n";
$r = $pdo->query("SELECT COUNT(*) FROM categories");
echo "Categories: " . $r->fetchColumn() . "\n";

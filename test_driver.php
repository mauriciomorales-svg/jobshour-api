<?php
$env = file_get_contents('/var/www/.env');
preg_match('/BROADCAST_DRIVER=(.+)/', $env, $m);
echo 'BROADCAST_DRIVER en .env: ' . trim($m[1] ?? 'no encontrado') . PHP_EOL;

// Verificar si hay config cacheada
$cached = '/var/www/bootstrap/cache/config.php';
if (file_exists($cached)) {
    $content = file_get_contents($cached);
    preg_match("/'default' => '([^']+)'/", $content, $m2);
    echo 'default en config cache: ' . ($m2[1] ?? 'no encontrado') . PHP_EOL;
} else {
    echo 'No hay config cache' . PHP_EOL;
}

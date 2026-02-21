<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'broadcast.default: ' . config('broadcasting.default') . PHP_EOL;
echo 'BROADCAST_DRIVER env: ' . env('BROADCAST_DRIVER') . PHP_EOL;

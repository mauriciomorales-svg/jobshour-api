<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = App\Models\ServiceRequest::query()
    ->where('status', 'completed')
    ->whereIn('payment_status', ['pending', 'failed'])
    ->orderByDesc('id')
    ->limit(8)
    ->get(['id', 'client_id', 'payment_status', 'mp_status', 'price']);

echo $rows->toJson(JSON_PRETTY_PRINT) . PHP_EOL;

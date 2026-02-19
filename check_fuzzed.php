<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$sr = App\Models\ServiceRequest::find(10);
echo 'client_location truthy: ' . ($sr->client_location ? 'YES' : 'NO') . "\n";
echo 'fuzzed_lat: ' . $sr->fuzzed_latitude . "\n";
echo 'fuzzed_lng: ' . $sr->fuzzed_longitude . "\n";

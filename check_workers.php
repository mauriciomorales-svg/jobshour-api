<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Workers count: " . \App\Models\Worker::count() . "\n";
echo "Users count: " . \App\Models\User::count() . "\n";

$workers = \App\Models\Worker::with('user')->take(3)->get();
foreach ($workers as $w) {
    echo "Worker ID: {$w->id}, User: {$w->user?->name}, Lat: {$w->latitude}, Lng: {$w->longitude}, Status: {$w->availability_status}\n";
}

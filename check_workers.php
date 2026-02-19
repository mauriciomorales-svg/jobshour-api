<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$pdo = new PDO('pgsql:host=localhost;port=5434;dbname=jobshour', 'jobshour', 'jobshour_secret');
echo "=== Workers por availability ===\n";
$rows = $pdo->query("SELECT availability, COUNT(*) as cnt FROM workers GROUP BY availability ORDER BY availability")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "{$r['availability']}: {$r['cnt']}\n";
echo "\n=== Detalle (primeros 15) ===\n";
$rows = $pdo->query("SELECT w.id, u.name, w.availability, w.hourly_rate FROM workers w JOIN users u ON u.id = w.user_id ORDER BY w.id LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "W{$r['id']} {$r['name']}: {$r['availability']} rate={$r['hourly_rate']}\n";

echo "Workers count: " . \App\Models\Worker::count() . "\n";
echo "Users count: " . \App\Models\User::count() . "\n";

$workers = \App\Models\Worker::with('user')->take(3)->get();
foreach ($workers as $w) {
    echo "Worker ID: {$w->id}, User: {$w->user?->name}, Lat: {$w->latitude}, Lng: {$w->longitude}, Status: {$w->availability_status}\n";
}

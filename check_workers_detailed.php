<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $count = \App\Models\Worker::count();
    echo "Total workers: $count\n\n";
    
    if ($count > 0) {
        $worker = \App\Models\Worker::with('user')->first();
        echo "Sample worker:\n";
        echo "ID: {$worker->id}\n";
        echo "User: {$worker->user?->name}\n";
        echo "Status: {$worker->availability_status}\n";
        echo "Hourly rate: {$worker->hourly_rate}\n";
        
        // Verificar si tiene location
        $hasLocation = \DB::select("SELECT ST_AsText(location) as loc FROM workers WHERE id = ?", [$worker->id]);
        if (!empty($hasLocation) && $hasLocation[0]->loc) {
            echo "Location: {$hasLocation[0]->loc}\n";
        } else {
            echo "Location: NULL\n";
        }
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}

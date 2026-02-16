<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$workers = App\Models\Worker::with('user')->available()->get();
echo "Workers disponibles: " . count($workers) . "\n";
foreach ($workers as $w) {
    echo "  - {$w->user->name} | {$w->title} | \${$w->hourly_rate}/hr | Rating: {$w->rating} | Lat: {$w->latitude} Lng: {$w->longitude}\n";
}

echo "\n";
$jobs = App\Models\Job::open()->count();
echo "Jobs abiertos: $jobs\n";

$openJobs = App\Models\Job::open()->get();
foreach ($openJobs as $j) {
    echo "  - {$j->title} | \${$j->budget} | {$j->urgency} | {$j->address}\n";
}

echo "\nTotal users: " . App\Models\User::count() . "\n";

<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo 'HOST=' . config('database.connections.pgsql.host') . "\n";
echo 'PORT=' . config('database.connections.pgsql.port') . "\n";
echo 'DB=' . config('database.connections.pgsql.database') . "\n";
echo 'USER=' . config('database.connections.pgsql.username') . "\n";

// Check demand 10 directly
$d = \App\Models\ServiceRequest::find(10);
echo "\nDEMAND 10: " . ($d ? "status={$d->status} worker_id={$d->worker_id}" : "NOT FOUND") . "\n";

$d22 = \App\Models\ServiceRequest::find(22);
echo "DEMAND 22: " . ($d22 ? "status={$d22->status} worker_id={$d22->worker_id}" : "NOT FOUND") . "\n";

// Check user from token
$user = \App\Models\User::find(21);
echo "\nUSER 21: " . ($user ? $user->name : "NOT FOUND") . "\n";
$worker = \App\Models\Worker::where('user_id', 21)->first();
echo "WORKER: " . ($worker ? "id={$worker->id} status={$worker->availability_status}" : "NOT FOUND") . "\n";

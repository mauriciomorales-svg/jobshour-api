<?php
require '/var/www/vendor/autoload.php';

$app = require '/var/www/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Test Pusher broadcast directamente
$pusher = new Pusher\Pusher(
    env('PUSHER_APP_KEY'),
    env('PUSHER_APP_SECRET'),
    env('PUSHER_APP_ID'),
    ['cluster' => env('PUSHER_APP_CLUSTER'), 'useTLS' => true]
);

echo 'PUSHER_KEY: ' . env('PUSHER_APP_KEY') . PHP_EOL;
echo 'PUSHER_CLUSTER: ' . env('PUSHER_APP_CLUSTER') . PHP_EOL;
echo 'BROADCAST_DRIVER: ' . env('BROADCAST_DRIVER') . PHP_EOL;

// Enviar evento de prueba al canal privado
$result = $pusher->trigger('private-chat.18', 'message.new', [
    'id' => 999,
    'sender_id' => 1,
    'sender_name' => 'Test',
    'body' => 'Test en tiempo real',
    'type' => 'text',
    'created_at' => date('c'),
]);

echo 'Broadcast result: ' . var_export($result, true) . PHP_EOL;

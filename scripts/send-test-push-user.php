<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$userId = (int) ($argv[1] ?? 24);
$user = App\Models\User::find($userId);
if (!$user || empty($user->fcm_token)) {
    fwrite(STDERR, "User {$userId} has no fcm_token\n");
    exit(1);
}

$firebase = new App\Services\FirebaseService();
echo "token_len=" . strlen($user->fcm_token) . " prefix=" . substr($user->fcm_token, 0, 25) . "...\n";

$ok = $firebase->sendToDevice(
    $user->fcm_token,
    'JobsHours — prueba',
    'Push de prueba desde el servidor. Si ves esto, FCM funciona.',
    ['type' => 'test', 'user_id' => (string) $userId],
);

if (!$ok) {
    $log = storage_path('logs/laravel.log');
    if (is_readable($log)) {
        echo "--- last FCM log lines ---\n";
        $lines = file($log);
        $slice = array_slice($lines, -15);
        echo implode('', $slice);
    }
}

echo $ok ? "OK sent to user {$userId}\n" : "FAILED\n";
exit($ok ? 0 : 1);

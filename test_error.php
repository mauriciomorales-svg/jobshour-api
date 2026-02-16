<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $user = App\Models\User::create([
        'name' => 'Test',
        'email' => 'test@test.cl',
        'phone' => '123',
        'password' => bcrypt('password'),
        'type' => 'worker',
    ]);
    echo "User created: ID={$user->id}\n";
    
    $token = $user->createToken('test')->plainTextToken;
    echo "Token: $token\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

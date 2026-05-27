<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pk = config('mercadopago.public_key');
$at = config('mercadopago.access_token');
echo 'public_key_prefix=' . substr($pk, 0, 20) . PHP_EOL;
echo 'access_token_suffix=' . substr($at, -15) . PHP_EOL;
echo 'sandbox=' . (config('mercadopago.use_sandbox_checkout') ? 'true' : 'false') . PHP_EOL;

$r = Illuminate\Support\Facades\Http::withToken($at)->get('https://api.mercadopago.com/users/me');
echo 'users_me=' . $r->status() . ' id=' . ($r->json('id') ?? 'n/a') . PHP_EOL;

$tokenPayload = [
    'card_number' => '4168818844447115',
    'expiration_year' => '2030',
    'expiration_month' => '11',
    'security_code' => '123',
    'cardholder' => ['name' => 'APRO', 'identification' => ['type' => 'Otro', 'number' => '123456789']],
];
$t = Illuminate\Support\Facades\Http::post("https://api.mercadopago.com/v1/card_tokens?public_key={$pk}", $tokenPayload);
echo 'tokenize=' . $t->status() . ' id=' . ($t->json('id') ?? 'fail') . PHP_EOL;
$cardToken = $t->json('id');
if (!$cardToken) { exit(1); }

$p = Illuminate\Support\Facades\Http::withHeaders(['X-Idempotency-Key' => 'debug-apr-' . time()])
    ->withToken($at)
    ->post('https://api.mercadopago.com/v1/payments', [
        'transaction_amount' => 1080,
        'token' => $cardToken,
        'description' => 'debug',
        'installments' => 1,
        'payment_method_id' => 'visa',
        'capture' => false,
        'payer' => ['email' => 'test@test.com'],
    ]);
echo 'payment=' . $p->status() . PHP_EOL;
echo json_encode($p->json(), JSON_PRETTY_PRINT) . PHP_EOL;

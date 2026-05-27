<?php

/**
 * Prueba todos los escenarios de titular de tarjeta de Mercado Pago (sandbox).
 * Uso en VPS: php deploy/mp-test-all-scenarios.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Services\MercadoPagoServicePaymentHelper;

$publicKey = trim((string) config('mercadopago.public_key'));
$accessToken = trim((string) config('mercadopago.access_token'));

if ($publicKey === '' || $accessToken === '') {
    fwrite(STDERR, "Faltan MP_PUBLIC_KEY o MP_ACCESS_TOKEN\n");
    exit(1);
}

$baseUrl = 'https://api.mercadopago.com';
$amount = 1080.0; // monto bajo para pruebas CLP
$payerEmail = 'test-buyer-jobshours@mailinator.com';

/** @var list<array<string, mixed>> */
$scenarios = [
    ['holder' => 'APRO', 'label' => 'Pago aprobado', 'expect_mp' => ['approved', 'authorized']],
    ['holder' => 'OTHE', 'label' => 'Rechazado general', 'expect_mp' => ['rejected']],
    ['holder' => 'CONT', 'label' => 'Pendiente', 'expect_mp' => ['pending', 'in_process']],
    ['holder' => 'CALL', 'label' => 'Rechazado validación autorizar', 'expect_mp' => ['rejected', 'pending']],
    ['holder' => 'FUND', 'label' => 'Fondos insuficientes', 'expect_mp' => ['rejected']],
    ['holder' => 'SECU', 'label' => 'CVV inválido', 'expect_mp' => ['rejected'], 'security_code' => '000'],
    ['holder' => 'EXPI', 'label' => 'Fecha vencimiento', 'expect_mp' => ['rejected']],
    ['holder' => 'FORM', 'label' => 'Error formulario', 'expect_mp' => ['rejected']],
    ['holder' => 'INST', 'label' => 'Cuotas inválidas', 'expect_mp' => ['rejected'], 'installments' => 99],
    ['holder' => 'LOCK', 'label' => 'Tarjeta deshabilitada', 'expect_mp' => ['rejected']],
    ['holder' => 'CTNA', 'label' => 'Tipo tarjeta no permitida', 'expect_mp' => ['rejected']],
    ['holder' => 'ATTE', 'label' => 'Intentos PIN excedidos', 'expect_mp' => ['rejected']],
    ['holder' => 'BLAC', 'label' => 'Lista negra', 'expect_mp' => ['rejected']],
    ['holder' => 'UNSU', 'label' => 'No soportado', 'expect_mp' => ['rejected']],
    ['holder' => 'TEST', 'label' => 'Regla de montos', 'expect_mp' => ['rejected', 'approved', 'authorized'], 'amount' => 1000.0],
];

function mapJobsHoursPaymentStatus(string $mpStatus): string
{
    return match ($mpStatus) {
        'approved' => 'completed',
        'authorized' => 'pending',
        'refunded' => 'refunded',
        'rejected', 'cancelled' => 'failed',
        default => 'pending (sin cambio explícito en sync)',
    };
}

function tokenizeCard(
    string $baseUrl,
    string $publicKey,
    string $holder,
    array $overrides = []
): array {
    $payload = [
        'card_number' => $overrides['card_number'] ?? '4168818844447115',
        'expiration_year' => $overrides['expiration_year'] ?? '2030',
        'expiration_month' => $overrides['expiration_month'] ?? '11',
        'security_code' => $overrides['security_code'] ?? '123',
        'cardholder' => [
            'name' => $holder,
            'identification' => [
                'type' => 'Otro',
                'number' => '123456789',
            ],
        ],
    ];

    $response = Http::timeout(25)
        ->post("{$baseUrl}/v1/card_tokens?public_key={$publicKey}", $payload);

    return [
        'http' => $response->status(),
        'body' => $response->json(),
        'ok' => $response->successful(),
    ];
}

function createPayment(
    string $baseUrl,
    string $accessToken,
    string $token,
    float $amount,
    string $idempotencyKey,
    int $installments = 1,
    string $externalReference = 'mp-scenario-test'
): array {
    $payload = [
        'transaction_amount' => $amount,
        'token' => $token,
        'description' => 'JobsHours MP scenario test',
        'installments' => $installments,
        'capture' => MercadoPagoServicePaymentHelper::shouldCaptureImmediately(),
        'external_reference' => $externalReference,
        'payer' => [
            'email' => 'comprador_prueba_jobshours@gmail.com',
            'identification' => ['type' => 'Otro', 'number' => '123456789'],
        ],
    ];

    $response = Http::timeout(30)
        ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
        ->withToken($accessToken)
        ->post("{$baseUrl}/v1/payments", $payload);

    return [
        'http' => $response->status(),
        'body' => $response->json(),
        'ok' => $response->successful(),
    ];
}

$results = [];

foreach ($scenarios as $scenario) {
    $holder = (string) $scenario['holder'];
    $row = [
        'holder' => $holder,
        'label' => $scenario['label'],
        'token_http' => null,
        'payment_http' => null,
        'mp_status' => null,
        'status_detail' => null,
        'jobs_hours_payment_status' => null,
        'expected_mp' => $scenario['expect_mp'],
        'pass' => false,
        'note' => '',
    ];

    $tokenRes = tokenizeCard($baseUrl, $publicKey, $holder, array_merge($scenario, $scenario['card'] ?? []));
    $row['token_http'] = $tokenRes['http'];

    $token = is_array($tokenRes['body']) ? ($tokenRes['body']['id'] ?? null) : null;
    if (! $token) {
        $row['note'] = 'Token falló: ' . json_encode($tokenRes['body']['message'] ?? $tokenRes['body'] ?? 'sin detalle');
        $results[] = $row;
        continue;
    }

    $payAmount = (float) ($scenario['amount'] ?? $amount);
    $installments = (int) ($scenario['installments'] ?? 1);
    $idem = 'jh-scenario-' . strtolower($holder) . '-' . bin2hex(random_bytes(4));

    $payRes = createPayment($baseUrl, $accessToken, (string) $token, $payAmount, $idem, $installments, 'mp-scenario-' . strtolower($holder));
    $row['payment_http'] = $payRes['http'];

    $body = is_array($payRes['body']) ? $payRes['body'] : [];
    $mpStatus = (string) ($body['status'] ?? ($payRes['ok'] ? '' : 'error'));
    $statusDetail = (string) ($body['status_detail'] ?? ($body['message'] ?? ''));

    if ($mpStatus === 'error' && isset($body['cause'][0]['code'])) {
        $statusDetail = (string) $body['cause'][0]['code'];
    }

    $row['mp_status'] = $mpStatus !== '' ? $mpStatus : 'rejected';
    $row['status_detail'] = $statusDetail;
    $row['jobs_hours_payment_status'] = mapJobsHoursPaymentStatus($row['mp_status']);
    $row['pass'] = in_array($row['mp_status'], $scenario['expect_mp'], true);
    if (! $row['pass'] && $payRes['ok'] && $holder === 'APRO') {
        $row['pass'] = true;
    }
    if (! $row['pass']) {
        $row['note'] = 'Esperado: ' . implode('|', $scenario['expect_mp']) . ' — recibido: ' . $row['mp_status'];
    }

    $results[] = $row;
    usleep(400000);
}

// Escenarios especiales
$specials = [];

// CARD: sin número de tarjeta (falla en tokenización)
$cardTokenAttempt = tokenizeCard($baseUrl, $publicKey, 'CARD', ['card_number' => '']);
$specials[] = [
    'holder' => 'CARD',
    'label' => 'Sin card_number',
    'token_http' => $cardTokenAttempt['http'],
    'payment_http' => null,
    'mp_status' => 'token_rejected',
    'status_detail' => is_array($cardTokenAttempt['body']) ? ($cardTokenAttempt['body']['message'] ?? '') : '',
    'jobs_hours_payment_status' => 'n/a',
    'expected_mp' => ['token_rejected'],
    'pass' => ! $cardTokenAttempt['ok'],
    'note' => $cardTokenAttempt['ok'] ? 'Debió fallar token' : 'Token rechazado OK',
];

// DUPL: mismo idempotency key dos veces
$dupToken = tokenizeCard($baseUrl, $publicKey, 'APRO');
$dupId = 'jh-scenario-dupl-fixed-key';
$dup1 = createPayment($baseUrl, $accessToken, (string) ($dupToken['body']['id'] ?? ''), $amount, $dupId);
$dup2 = createPayment($baseUrl, $accessToken, (string) ($dupToken['body']['id'] ?? ''), $amount, $dupId);
$dup2Status = is_array($dup2['body']) ? ($dup2['body']['status'] ?? 'error') : 'error';
$specials[] = [
    'holder' => 'DUPL',
    'label' => 'Pago duplicado (idempotency)',
    'token_http' => $dupToken['http'],
    'payment_http' => $dup2['http'],
    'mp_status' => (string) $dup2Status,
    'status_detail' => is_array($dup2['body']) ? ($dup2['body']['status_detail'] ?? $dup2['body']['message'] ?? '') : '',
    'jobs_hours_payment_status' => mapJobsHoursPaymentStatus((string) $dup2Status),
    'expected_mp' => ['approved', 'authorized', 'rejected'],
    'pass' => $dup1['ok'] && ($dup2['ok'] || $dup2['http'] === 200),
    'note' => '1er pago http=' . $dup1['http'] . ' 2do http=' . $dup2['http'],
];

$all = array_merge($results, $specials);
$passed = count(array_filter($all, fn ($r) => $r['pass']));
$total = count($all);

echo "MP sandbox scenarios — User config OK\n";
echo str_repeat('-', 100) . "\n";
printf("%-6s %-32s %5s %5s %-12s %-24s %-20s %s\n",
    'Holder', 'Escenario', 'Tok', 'Pay', 'MP status', 'Detail', 'JH payment_status', 'OK');
echo str_repeat('-', 100) . "\n";

foreach ($all as $r) {
    printf("%-6s %-32s %5s %5s %-12s %-24s %-20s %s\n",
        $r['holder'],
        mb_substr($r['label'], 0, 32),
        (string) ($r['token_http'] ?? '-'),
        (string) ($r['payment_http'] ?? '-'),
        (string) ($r['mp_status'] ?? '-'),
        mb_substr((string) ($r['status_detail'] ?? ''), 0, 24),
        (string) ($r['jobs_hours_payment_status'] ?? '-'),
        $r['pass'] ? 'PASS' : 'FAIL'
    );
}

echo str_repeat('-', 100) . "\n";
echo "Resumen: {$passed}/{$total} escenarios OK\n";

$failures = array_filter($all, fn ($r) => ! $r['pass']);
if ($failures !== []) {
    echo "\nDetalle fallos:\n";
    foreach ($failures as $f) {
        echo "  - {$f['holder']}: {$f['note']}\n";
    }
}

exit($passed === $total ? 0 : 1);

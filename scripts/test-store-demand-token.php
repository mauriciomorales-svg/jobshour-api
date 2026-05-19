<?php

$token = $argv[1] ?? '';
if ($token === '') {
    fwrite(STDERR, "Usage: php test-store-demand-token.php <token>\n");
    exit(1);
}

$payload = [
    'external_order_id' => 'dm-setup-test-'.time(),
    'description' => 'Test integracion DondeMorales',
    'lat' => -37.6672,
    'lng' => -72.5730,
    'buyer_email' => 'test-setup@dondemorales.cl',
    'buyer_name' => 'Test Setup',
    'offered_price' => 2000,
];

$urls = [
    'http://127.0.0.1:8095/api/v1/integrations/store-demand',
    'https://jobshours.com/api/v1/integrations/store-demand',
];

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "URL={$url}\nHTTP={$code}\n".substr((string) $body, 0, 400)."\n---\n";
}

<?php
/**
 * Simulación de humo contra producción (solo lectura + endpoints públicos / 401 esperados).
 * Uso: php scripts/simulate-prod-smoke.php
 *      php scripts/simulate-prod-smoke.php https://jobshours.com
 */

$base = rtrim($argv[1] ?? 'https://jobshours.com', '/');
$api = $base.'/api/v1';

$angol = ['lat' => -37.6672, 'lng' => -72.5730];
$santiago = ['lat' => -33.4489, 'lng' => -70.6693];

$results = [];

function req(string $method, string $url, ?array $json = null, array $headers = []): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($body) && $body !== '' && ($body[0] === '{' || $body[0] === '[')) {
        $decoded = json_decode($body, true);
    }

    return ['code' => $code, 'body' => $body, 'json' => $decoded, 'error' => $err];
}

function scenario(string $id, string $title, callable $fn): void
{
    global $results;
    try {
        $out = $fn();
        $results[] = array_merge(['id' => $id, 'title' => $title], $out);
    } catch (Throwable $e) {
        $results[] = ['id' => $id, 'title' => $title, 'ok' => false, 'detail' => $e->getMessage()];
    }
}

function ok(bool $cond, string $detail, int $code = 0): array
{
    return ['ok' => $cond, 'detail' => $detail, 'http' => $code];
}

echo "=== JobsHours smoke producción ===\nBase: {$base}\n\n";

scenario('S01', 'Health API', function () use ($api) {
    $r = req('GET', $api.'/health');
    $j = $r['json'];
    $checks = $j['checks'] ?? [];
    $detail = sprintf(
        'HTTP %d | db=%s horizon=%s reverb=%s workers=%s',
        $r['code'],
        $checks['database'] ?? '?',
        $checks['horizon'] ?? '?',
        $checks['reverb'] ?? '?',
        $checks['active_workers'] ?? '?'
    );

    return ok($r['code'] === 200 && ($j['status'] ?? '') === 'ok', $detail, $r['code']);
});

scenario('S02', 'Web raíz', function () use ($base) {
    $ch = curl_init($base.'/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ok($code === 200, "HTTP {$code} (app Next)", $code);
});

scenario('S03', 'Categorías públicas', function () use ($api) {
    $r = req('GET', $api.'/categories');
    $list = $r['json']['data'] ?? $r['json'];
    $count = is_array($list) ? count($list) : 0;

    return ok($r['code'] === 200 && $count > 0, "HTTP {$r['code']} | categorías={$count}", $r['code']);
});

scenario('S04', 'Mapa Angol (experts nearby)', function () use ($api, $angol) {
    $q = http_build_query(['lat' => $angol['lat'], 'lng' => $angol['lng'], 'radius' => 50]);
    $r = req('GET', $api.'/experts/nearby?'.$q);
    $j = $r['json'];
    $n = is_array($j['data'] ?? null) ? count($j['data']) : 0;
    $outside = $j['meta']['outside_zone'] ?? false;

    return ok($r['code'] === 200 && ! $outside && $n > 0, "HTTP {$r['code']} | puntos={$n} outside=".($outside ? 'yes' : 'no'), $r['code']);
});

scenario('S05', 'Mapa Santiago (sin geocerca activa → debe haber datos)', function () use ($api, $santiago) {
    $q = http_build_query(['lat' => $santiago['lat'], 'lng' => $santiago['lng'], 'radius' => 50]);
    $r = req('GET', $api.'/experts/nearby?'.$q);
    $j = $r['json'];
    $outside = $j['meta']['outside_zone'] ?? false;
    $n = is_array($j['data'] ?? null) ? count($j['data']) : 0;
    // Con geofence off, no debería marcar outside_zone; puede haber 0 workers en RM
    $pass = $r['code'] === 200 && ! $outside;

    return ok($pass, "HTTP {$r['code']} | puntos={$n} outside=".($outside ? 'yes' : 'no'), $r['code']);
});

scenario('S06', 'Demandas en mapa Angol', function () use ($api, $angol) {
    $q = http_build_query(['lat' => $angol['lat'], 'lng' => $angol['lng']]);
    $r = req('GET', $api.'/demand/nearby?'.$q);
    $n = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;

    return ok($r['code'] === 200, "HTTP {$r['code']} | demandas={$n}", $r['code']);
});

scenario('S07', 'Info zona piloto', function () use ($api) {
    $r = req('GET', $api.'/zone-info');
    $enabled = $r['json']['enabled'] ?? null;
    $name = $r['json']['zone_name'] ?? '';

    return ok($r['code'] === 200, 'HTTP '.$r['code'].' | enabled='.json_encode($enabled).' zone='.$name, $r['code']);
});

scenario('S08', 'Store-demand sin token → 401', function () use ($api, $angol) {
    $r = req('POST', $api.'/integrations/store-demand', [
        'external_order_id' => 'smoke-'.time(),
        'description' => 'Smoke test',
        'lat' => $angol['lat'],
        'lng' => $angol['lng'],
    ]);

    return ok($r['code'] === 401, 'HTTP '.$r['code'].' (esperado 401)', $r['code']);
});

scenario('S09', 'Flow init sin sesión / standby → 401 o 503', function () use ($api) {
    $r = req('POST', $api.'/payments/flow/init', [
        'service_request_id' => 1,
        'amount' => 1000,
    ]);
    $pass = in_array($r['code'], [401, 403, 503], true);
    $msg = $r['json']['message'] ?? '';

    return ok($pass, "HTTP {$r['code']} | {$msg}", $r['code']);
});

scenario('S10', 'Reseñas públicas worker id=2', function () use ($api) {
    $r = req('GET', $api.'/workers/2/reviews');
    $n = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;

    return ok($r['code'] === 200, "HTTP {$r['code']} | reseñas={$n}", $r['code']);
});

scenario('S11', 'Perfil worker público API', function () use ($base) {
    $r = req('GET', $base.'/api/workers/2');
    $pass = $r['code'] === 200;

    return ok($pass, 'HTTP '.$r['code'], $r['code']);
});

scenario('S12', 'Publicar demanda sin auth → 401', function () use ($api, $angol) {
    $r = req('POST', $api.'/demand/publish', [
        'description' => 'Smoke',
        'lat' => $angol['lat'],
        'lng' => $angol['lng'],
        'category_id' => 1,
    ]);

    return ok($r['code'] === 401, 'HTTP '.$r['code'].' (esperado 401)', $r['code']);
});

scenario('S13', 'Crear reseña sin auth → 401', function () use ($api) {
    $r = req('POST', $api.'/reviews', [
        'service_request_id' => 1,
        'stars' => 5,
        'comment' => 'Comentario de prueba con más de diez caracteres.',
    ]);

    return ok($r['code'] === 401, 'HTTP '.$r['code'].' (esperado 401)', $r['code']);
});

scenario('S14', 'Página términos y privacidad web', function () use ($base) {
    $t = req('GET', $base.'/terminos');
    $p = req('GET', $base.'/privacidad');

    return ok($t['code'] === 200 && $p['code'] === 200, "terminos={$t['code']} privacidad={$p['code']}", $t['code']);
});

$passed = 0;
$failed = 0;
foreach ($results as $row) {
    $icon = ($row['ok'] ?? false) ? '✅' : '❌';
    if ($row['ok'] ?? false) {
        $passed++;
    } else {
        $failed++;
    }
    echo "{$icon} [{$row['id']}] {$row['title']}\n    {$row['detail']}\n";
}

echo "\n--- Resumen: {$passed} OK, {$failed} fallos ---\n";
exit($failed > 0 ? 1 : 0);

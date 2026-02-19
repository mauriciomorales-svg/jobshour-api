<?php

$url = "http://localhost:8095/api/v1/dashboard/feed?lat=-37.6672&lng=-72.5730&cursor=0";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "🔍 Probando endpoint del feed...\n";
echo "URL: $url\n";
echo "HTTP Code: $httpCode\n\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    if ($data && isset($data['data']) && is_array($data['data'])) {
        $feed = $data['data'];
        echo "✅ Feed recibido correctamente\n";
        echo "📊 Total de items en feed: " . count($feed) . "\n";
        echo "📊 Meta: next_cursor=" . ($data['meta']['next_cursor'] ?? 'N/A') . ", total_returned=" . ($data['meta']['total_returned'] ?? 'N/A') . "\n\n";
        
        foreach ($feed as $index => $item) {
            echo sprintf(
                "[%d] ID: %s | Type: %s | Price: $%s | Urgency: %s | Description: %s\n",
                $index + 1,
                $item['id'] ?? 'N/A',
                $item['type'] ?? 'N/A',
                isset($item['offered_price']) ? number_format($item['offered_price']) : '0',
                $item['urgency'] ?? 'N/A',
                substr($item['description'] ?? 'N/A', 0, 40)
            );
        }
    } else {
        echo "❌ Respuesta inválida:\n";
        echo substr($response, 0, 1000) . "\n";
    }
} else {
    echo "❌ Error HTTP $httpCode\n";
    echo "Response: " . substr($response, 0, 500) . "\n";
}

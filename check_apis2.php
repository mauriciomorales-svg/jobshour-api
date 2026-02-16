<?php
// Test simple de APIs con más detalle
$url = 'http://localhost:8002/api/v1/categories';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$body = substr($response, $headerSize);

echo "=== API Categories ===\n";
echo "HTTP Code: " . $httpCode . "\n";
echo "Response:\n" . $body . "\n\n";

// Test nearby experts
$url2 = 'http://localhost:8002/api/v1/experts/nearby?lat=-37.6672&lng=-72.5730';
$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "=== API Experts Nearby ===\n";
echo "HTTP Code: " . $httpCode2 . "\n";
echo "Response:\n" . $response2 . "\n";

<?php
// Test simple de APIs
$url = 'http://localhost:8002/api/v1/categories';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "=== API Categories ===\n";
echo "HTTP Code: " . $httpCode . "\n";
echo "CURL Error: " . ($curlError ?: 'None') . "\n";
echo "Response (first 300 chars):\n" . substr($response, 0, 300) . "\n\n";

// Test nearby experts
$url2 = 'http://localhost:8002/api/v1/experts/nearby?lat=-37.6672&lng=-72.5730';
$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$curlError2 = curl_error($ch2);
curl_close($ch2);

echo "=== API Experts Nearby ===\n";
echo "HTTP Code: " . $httpCode2 . "\n";
echo "CURL Error: " . ($curlError2 ?: 'None') . "\n";
echo "Response (first 500 chars):\n" . substr($response2, 0, 500) . "\n";

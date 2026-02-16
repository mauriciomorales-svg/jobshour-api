<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Reverse geocode: coords → city/town name.
     * Uses Nominatim (OpenStreetMap) with aggressive caching.
     * Returns ['city' => 'Renaico', 'region' => 'Araucanía', 'country' => 'Chile']
     */
    public static function reverseGeocode(float $lat, float $lng): array
    {
        // Round to ~1km precision for file-based cache
        $roundedLat = round($lat, 2);
        $roundedLng = round($lng, 2);
        $cacheFile = storage_path("app/geocode/{$roundedLat}_{$roundedLng}.json");

        // Check file cache (30 days TTL)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        $result = ['city' => null, 'region' => null, 'country' => null];

        try {
            $url = 'https://nominatim.openstreetmap.org/reverse?'
                . http_build_query([
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'zoom' => 10,
                    'accept-language' => 'es',
                ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'Jobshour/1.0 (https://jobshour.dondemorales.cl)',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $body) {
                $data = json_decode($body, true);
                $address = $data['address'] ?? [];

                $result = [
                    'city' => $address['city']
                        ?? $address['town']
                        ?? $address['village']
                        ?? $address['municipality']
                        ?? null,
                    'region' => $address['state'] ?? $address['region'] ?? null,
                    'country' => $address['country'] ?? null,
                ];

                // Write file cache
                $dir = dirname($cacheFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($cacheFile, json_encode($result));
            }
        } catch (\Throwable $e) {
            Log::warning('Geocoding failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Quick helper: returns just the city name or fallback.
     */
    public static function getCityName(float $lat, float $lng, string $fallback = 'Tu zona'): string
    {
        $result = self::reverseGeocode($lat, $lng);
        return $result['city'] ?? $fallback;
    }
}

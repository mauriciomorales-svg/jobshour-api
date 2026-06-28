<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    private const CACHE_DAYS = 30;

    /**
     * Reverse geocode: coords → city/town name (según posición real del usuario).
     */
    public static function reverseGeocode(float $lat, float $lng): array
    {
        $roundedLat = round($lat, 2);
        $roundedLng = round($lng, 2);
        $cacheFile = storage_path("app/geocode/{$roundedLat}_{$roundedLng}.json");

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < (self::CACHE_DAYS * 86400)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = ['city' => null, 'region' => null, 'country' => null];

        try {
            $url = 'https://nominatim.openstreetmap.org/reverse?'
                . http_build_query([
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'zoom' => 14,
                    'accept-language' => 'es',
                ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'Jobshours/1.0 (https://jobshours.com; contacto@jobshour.cl)',
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
                    'city' => self::pickLocalityName($address, $data),
                    'region' => $address['state'] ?? $address['region'] ?? null,
                    'country' => $address['country'] ?? null,
                ];

                $dir = dirname($cacheFile);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($cacheFile, json_encode($result));
            }
        } catch (\Throwable $e) {
            Log::warning('Geocoding failed', ['lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Nombre legible de la zona (comuna/ciudad) o fallback.
     */
    public static function getCityName(float $lat, float $lng, string $fallback = 'Tu zona'): string
    {
        $cacheKey = sprintf('geo_city_%s_%s', round($lat, 3), round($lng, 3));

        return Cache::remember($cacheKey, now()->addDays(self::CACHE_DAYS), function () use ($lat, $lng, $fallback) {
            $result = self::reverseGeocode($lat, $lng);

            return $result['city'] ?: $fallback;
        });
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  array<string, mixed>  $payload
     */
    private static function pickLocalityName(array $address, array $payload = []): ?string
    {
        $addresstype = $payload['addresstype'] ?? null;
        $name = $payload['name'] ?? null;
        if (is_string($name) && in_array($addresstype, ['hamlet', 'village', 'suburb', 'neighbourhood', 'locality'], true)) {
            return $name;
        }

        // Preferir localidad más cercana al GPS antes que comuna (town).
        foreach ([
            'hamlet',
            'suburb',
            'village',
            'locality',
            'neighbourhood',
            'city',
            'town',
            'municipality',
        ] as $key) {
            if (! empty($address[$key]) && is_string($address[$key])) {
                return $address[$key];
            }
        }

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return null;
    }
}

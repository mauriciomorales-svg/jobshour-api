<?php

namespace App\Services;

class CityDetector
{
    // Comunas de Biobío + La Araucanía con coordenadas centrales
    private static array $cities = [
        // Biobío
        ['name' => 'Los Ángeles',   'lat' => -37.4694, 'lng' => -72.3527],
        ['name' => 'Nacimiento',    'lat' => -37.5028, 'lng' => -72.6806],
        ['name' => 'Mulchén',       'lat' => -37.7167, 'lng' => -72.2333],
        ['name' => 'Santa Bárbara', 'lat' => -37.6667, 'lng' => -72.0167],
        ['name' => 'Quilaco',       'lat' => -37.6833, 'lng' => -71.9500],
        ['name' => 'Yumbel',        'lat' => -37.1000, 'lng' => -72.5333],
        ['name' => 'Cabrero',       'lat' => -37.0333, 'lng' => -72.4000],
        ['name' => 'Laja',          'lat' => -37.2667, 'lng' => -72.7000],
        ['name' => 'San Rosendo',   'lat' => -37.2667, 'lng' => -72.7167],
        ['name' => 'Concepción',    'lat' => -36.8270, 'lng' => -73.0498],
        ['name' => 'Chillán',       'lat' => -36.6063, 'lng' => -72.1034],
        // La Araucanía
        ['name' => 'Angol',         'lat' => -37.7963, 'lng' => -72.7089],
        ['name' => 'Renaico',       'lat' => -37.6500, 'lng' => -72.5833],
        ['name' => 'Collipulli',    'lat' => -37.9500, 'lng' => -72.4333],
        ['name' => 'Ercilla',       'lat' => -37.8667, 'lng' => -72.3833],
        ['name' => 'Victoria',      'lat' => -38.2333, 'lng' => -72.3333],
        ['name' => 'Traiguén',      'lat' => -38.2500, 'lng' => -72.6667],
        ['name' => 'Lumaco',        'lat' => -38.1500, 'lng' => -72.9000],
        ['name' => 'Purén',         'lat' => -38.0167, 'lng' => -73.0833],
        ['name' => 'Los Sauces',    'lat' => -37.9833, 'lng' => -72.8333],
        ['name' => 'Temuco',        'lat' => -38.7333, 'lng' => -72.6000],
        ['name' => 'Padre Las Casas', 'lat' => -38.7667, 'lng' => -72.6167],
        ['name' => 'Villarrica',    'lat' => -39.2833, 'lng' => -72.2333],
        ['name' => 'Pucón',         'lat' => -39.2667, 'lng' => -71.9667],
        ['name' => 'Lautaro',       'lat' => -38.5333, 'lng' => -72.4333],
        ['name' => 'Curacautín',    'lat' => -38.4333, 'lng' => -71.8833],
        ['name' => 'Tijeral',       'lat' => -37.5180, 'lng' => -72.7150],
    ];

    public static function detect(float $lat, float $lng, string $fallback = 'Tu zona'): string
    {
        $closest = $fallback;
        $minDist = PHP_FLOAT_MAX;

        foreach (self::$cities as $city) {
            $dist = self::haversine($lat, $lng, $city['lat'], $city['lng']);
            if ($dist < $minDist) {
                $minDist = $dist;
                $closest = $city['name'];
            }
        }

        // Si está a más de 80km de cualquier ciudad conocida, devolver fallback
        return $minDist < 80 ? $closest : $fallback;
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
    }
}

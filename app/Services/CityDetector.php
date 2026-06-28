<?php

namespace App\Services;

/**
 * Nombre de ciudad/comuna según las coordenadas del usuario conectado (GPS).
 * Sin tabla fija de comunas: usa reverse geocoding (OpenStreetMap / Nominatim).
 */
class CityDetector
{
    public static function detect(float $lat, float $lng, string $fallback = 'Tu zona'): string
    {
        return GeocodingService::getCityName($lat, $lng, $fallback);
    }
}

<?php

namespace App\Support;

/**
 * Utilidades de geofencing para la Zona Piloto.
 */
class Geofence
{
    /**
     * ¿Está el geofencing activado?
     */
    public static function enabled(): bool
    {
        return (bool) config('geofence.enabled', false);
    }

    /**
     * Distancia haversine en km entre dos puntos.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * asin(sqrt($a));
    }

    /**
     * ¿Está el punto (lat, lng) dentro de la zona piloto?
     * Si el geofencing está desactivado, siempre devuelve true.
     */
    public static function isInsideZone(float $lat, float $lng): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $centerLat = (float) config('geofence.center_lat');
        $centerLng = (float) config('geofence.center_lng');
        $radiusKm  = (float) config('geofence.radius_km');

        return self::distanceKm($lat, $lng, $centerLat, $centerLng) <= $radiusKm;
    }

    /**
     * Radio máximo permitido para búsquedas en el mapa.
     * Protege el VPS de queries PostGIS enormes.
     */
    public static function maxSearchRadiusKm(): float
    {
        return (float) config('geofence.max_search_radius_km', 25);
    }

    /**
     * Datos de la zona para exponer al frontend.
     */
    public static function zoneInfo(): array
    {
        return [
            'enabled'    => self::enabled(),
            'center_lat' => (float) config('geofence.center_lat'),
            'center_lng' => (float) config('geofence.center_lng'),
            'radius_km'  => (float) config('geofence.radius_km'),
            'zone_name'  => (string) config('geofence.zone_name', 'Zona piloto'),
        ];
    }
}

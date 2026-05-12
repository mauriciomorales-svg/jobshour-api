<?php

/**
 * Configuración de geofencing para la Zona Piloto.
 *
 * Para activar: en .env agregar:
 *   GEOFENCE_ENABLED=true
 *   GEOFENCE_CENTER_LAT=-37.6672
 *   GEOFENCE_CENTER_LNG=-72.5730
 *   GEOFENCE_RADIUS_KM=15
 *   GEOFENCE_ZONE_NAME="Angol, La Araucanía"
 *
 * Mientras GEOFENCE_ENABLED=false, todo funciona igual que antes.
 */
return [

    /*
     * ¿Está activo el geofencing?
     * false = abierto a todo el mundo (comportamiento actual)
     * true  = solo se muestran workers/demandas dentro del radio configurado
     */
    'enabled' => (bool) env('GEOFENCE_ENABLED', false),

    /*
     * Centro de la zona piloto (lat/lng del centro del barrio/ciudad)
     */
    'center_lat' => (float) env('GEOFENCE_CENTER_LAT', -37.6672),
    'center_lng' => (float) env('GEOFENCE_CENTER_LNG', -72.5730),

    /*
     * Radio máximo en km desde el centro.
     * Dentro: se ve el mapa normal.
     * Fuera: se muestra la pantalla "fuera de cobertura" con lista de espera.
     */
    'radius_km' => (float) env('GEOFENCE_RADIUS_KM', 15),

    /*
     * Nombre legible de la zona para mostrar en la UI
     */
    'zone_name' => env('GEOFENCE_ZONE_NAME', 'Zona piloto'),

    /*
     * Radio máximo que se permite buscar en el mapa (cap duro).
     * Evita queries PostGIS sobre radios enormes que degradan el VPS.
     * En zona piloto se recomienda 25km; en apertura nacional 100km.
     */
    'max_search_radius_km' => (float) env('GEOFENCE_MAX_SEARCH_KM', 25),
];

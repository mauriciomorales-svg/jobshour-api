<?php

/**
 * SLAs inspirados en apps tipo Uber (ventanas cortas al aceptar),
 * adaptados a trabajo presencial / mandados (no 15 s rígidos de viaje).
 *
 * Override vía .env en producción si hace falta.
 */
return [

    /*
    | Segundos que tiene el trabajador para pulsar "Aceptar" en Mis solicitudes
    | después de POST /demand/{id}/take (o take-public). Uber da ~15–30 s al
    | conductor para aceptar un viaje; aquí 90 s por defecto (red con latencia).
    */
    'map_take_worker_accept_seconds' => (int) env('JH_MAP_TAKE_ACCEPT_SECONDS', 90),

    /*
    | Minutos para aceptar/rechazar una solicitud enviada desde tienda o perfil
    | (el trabajador suele leer más contexto).
    */
    'direct_booking_worker_accept_minutes' => (int) env('JH_DIRECT_BOOKING_ACCEPT_MINUTES', 5),
];

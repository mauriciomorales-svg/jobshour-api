<?php

return [
    'name' => env('APP_NAME', 'Jobshour'),
    'env' => env('APP_ENV', 'local'),
    'debug' => (bool) env('APP_DEBUG', true),
    'url' => env('APP_URL', 'http://localhost'),
    // URL pública para retornos de pagos/webhooks/redirecciones.
    // Ej: Flow / Flow.cl usa esto para urlReturn.
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),
    /** Dominio principal del sitio (sin protocolo). Bloqueado para `public_store_host` de tiendas. */
    'primary_public_host' => strtolower(trim((string) env('PRIMARY_PUBLIC_HOST', 'jobshours.com'))),
    'timezone' => env('APP_TIMEZONE', 'America/Santiago'),
    'locale' => env('APP_LOCALE', 'es'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'es_CL'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
    ],
];

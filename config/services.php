<?php

return [
    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
    ],
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],
    'flow' => [
        // ⚠️ CONFIGURACIÓN PENDIENTE: Obtener claves de Flow.cl
        // Ver: PENDIENTE_CONFIGURACION_FLOW.md
        'api_key' => env('FLOW_API_KEY', ''),
        'secret_key' => env('FLOW_SECRET_KEY', ''),
        'sandbox' => env('FLOW_SANDBOX', true), // Por defecto en sandbox hasta configurar
    ],
];

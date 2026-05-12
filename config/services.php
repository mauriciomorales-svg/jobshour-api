<?php

return [
    'mercadopago' => [
        'access_token' => env('MP_ACCESS_TOKEN') ?: env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MP_PUBLIC_KEY') ?: env('MERCADOPAGO_PUBLIC_KEY'),
        /** Secret de firma HMAC en webhooks (panel MP → tu integración). Vacío = no validar (solo dev). */
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET', ''),
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
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
    ],
    'analytics' => [
        'ingest_secret' => env('ANALYTICS_INGEST_SECRET'),
        /** Días de retención de filas en product_analytics_events (comando analytics:prune). */
        'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 365),
    ],

    'retention' => [
        /** Horas entre pushes FCM duplicados por usuario (retention:push-open-requests). */
        'push_cooldown_hours' => (int) env('RETENTION_PUSH_COOLDOWN_HOURS', 24),
    ],

    /** Boost de visibilidad en mapa (Checkout Pro Mercado Pago). */
    'boost' => [
        'demand_price_clp' => (int) env('BOOST_DEMAND_PRICE_CLP', 4990),
        'default_hours' => (int) env('BOOST_DEMAND_HOURS', 24),
    ],

    /**
     * Paquetes de créditos para ver teléfono de trabajadores.
     * Precio en CLP; créditos que se acreditan al usuario.
     * Se puede sobreescribir con CREDITS_PACKS_JSON='[{"id":"pack5",...}]'.
     */
    'credits' => [
        'packs' => json_decode((string) env('CREDITS_PACKS_JSON', ''), true) ?: [
            ['id' => 'pack5',  'credits' => 5,  'price_clp' => 1490, 'label' => '5 contactos'],
            ['id' => 'pack15', 'credits' => 15, 'price_clp' => 3490, 'label' => '15 contactos — más popular'],
            ['id' => 'pack30', 'credits' => 30, 'price_clp' => 5990, 'label' => '30 contactos — mejor valor'],
        ],
    ],

    /** NULL de ip/user_agent en analytics pasados N días (analytics:anonymize-pii). */
    'pii' => [
        'anonymize_after_days' => (int) env('ANALYTICS_PII_ANONYMIZE_AFTER_DAYS', 90),
    ],

    /** Webhook Slack (opcional): resumen cohorte semanal. */
    'slack' => [
        'analytics_webhook_url' => env('ANALYTICS_SLACK_WEBHOOK_URL'),
    ],

    'flow' => [
        // ⚠️ CONFIGURACIÓN PENDIENTE: Obtener claves de Flow.cl
        // Ver: PENDIENTE_CONFIGURACION_FLOW.md
        'api_key' => env('FLOW_API_KEY', ''),
        'secret_key' => env('FLOW_SECRET_KEY', ''),
        'sandbox' => env('FLOW_SANDBOX', true), // Por defecto en sandbox hasta configurar
    ],
];

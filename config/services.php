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

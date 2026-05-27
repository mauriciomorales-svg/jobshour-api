<?php

return [
    // Misma cuenta puede usarse en inventario-api (Point) con MERCADOPAGO_ACCESS_TOKEN
    'access_token' => env('MP_ACCESS_TOKEN', '') ?: env('MERCADOPAGO_ACCESS_TOKEN', ''),
    'public_key'   => env('MP_PUBLIC_KEY', '') ?: env('MERCADOPAGO_PUBLIC_KEY', ''),
    /**
     * Si true, los webhooks MP (pagos JobsHours + tienda) procesan en el mismo request (útil en local sin worker).
     * En producción debe ser false para responder rápido a MP y procesar en cola.
     */
    'webhook_sync' => (bool) env('MP_WEBHOOK_SYNC', false),
    /** Solo local/staging: permitir webhooks sin firma si falta MERCADOPAGO_WEBHOOK_SECRET */
    'webhook_allow_unsigned' => (bool) env('MP_WEBHOOK_ALLOW_UNSIGNED', false),
];

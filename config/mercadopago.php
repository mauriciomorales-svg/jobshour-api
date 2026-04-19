<?php

return [
    // Misma cuenta puede usarse en inventario-api (Point) con MERCADOPAGO_ACCESS_TOKEN
    'access_token' => env('MP_ACCESS_TOKEN', '') ?: env('MERCADOPAGO_ACCESS_TOKEN', ''),
    'public_key'   => env('MP_PUBLIC_KEY', '') ?: env('MERCADOPAGO_PUBLIC_KEY', ''),
];

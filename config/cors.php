<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://jobshour.dondemorales.cl',
        'http://localhost:3000',
        'http://localhost:3002',
    ],
    'allowed_origins_patterns' => ['#.*#'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];

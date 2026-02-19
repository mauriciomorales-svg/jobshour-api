<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'JobsHour API',
        'version' => '1.0',
        'note' => 'This is the API backend. The frontend is served separately.',
        'endpoints' => [
            'api/v1/dashboard/feed' => 'GET - Feed de oportunidades',
            'api/v1/dashboard/live-stats' => 'GET - Estadísticas en vivo',
            'api/v1/demand/nearby' => 'GET - Demandas cercanas',
            'api/v1/diagnostic/check' => 'GET - Diagnostic check',
        ]
    ], 200, [], JSON_UNESCAPED_SLASHES);
});

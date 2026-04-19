<?php

return [
    /**
     * IDs de usuarios con acceso al panel admin (API Sanctum).
     * Lista separada por comas; por defecto 21 (legacy).
     */
    'user_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('ADMIN_USER_IDS', '21'))
    ))),
];

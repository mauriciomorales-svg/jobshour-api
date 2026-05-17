<?php

return [
    'credentials' => [
        'file' => env('FIREBASE_CREDENTIALS', storage_path('firebase/jobshours-firebase-adminsdk-fbsvc-a52be09a7f.json')),
    ],

    'database' => [
        'url' => env('FIREBASE_DATABASE_URL'),
    ],

    'project_id' => env('FIREBASE_PROJECT_ID', 'jobshours'),

    /** Clave web para fcmregistrations (sin restricción HTTP referrer en servidor). */
    'web_api_key' => env('FIREBASE_WEB_API_KEY', env('NEXT_PUBLIC_FIREBASE_API_KEY')),
];

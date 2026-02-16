<?php

return [
    'credentials' => [
        'file' => env('FIREBASE_CREDENTIALS', storage_path('firebase/jobshours-firebase-adminsdk-fbsvc-a52be09a7f.json')),
    ],

    'database' => [
        'url' => env('FIREBASE_DATABASE_URL'),
    ],

    'project_id' => env('FIREBASE_PROJECT_ID'),
];

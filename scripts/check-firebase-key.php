<?php

$path = __DIR__ . '/../storage/firebase/jobshours-firebase-adminsdk-fbsvc-a52be09a7f.json';
$c = json_decode(file_get_contents($path), true);
echo 'email=' . ($c['client_email'] ?? '?') . PHP_EOL;
echo 'pk_len=' . strlen($c['private_key'] ?? '') . PHP_EOL;
$k = openssl_pkey_get_private($c['private_key']);
echo 'pkey_ok=' . ($k !== false ? 'yes' : 'no') . PHP_EOL;

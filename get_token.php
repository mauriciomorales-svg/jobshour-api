<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$u = App\Models\User::first();
$t = $u->createToken('test')->plainTextToken;
echo $u->email . '|' . $t;

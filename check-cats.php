<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$cats = \Illuminate\Support\Facades\DB::table('categories')->orderBy('sort_order')->get();
foreach ($cats as $c) echo "ID:{$c->id} | {$c->display_name} | {$c->icon}\n";
echo "Total: ".count($cats)."\n";

<?php

$providers = [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Illuminate\Broadcasting\BroadcastServiceProvider::class,
];

// Telescope es require-dev: en prod (--no-dev) no existe la clase base; no registrar el provider.
if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
    $providers[] = App\Providers\TelescopeServiceProvider::class;
}

return $providers;

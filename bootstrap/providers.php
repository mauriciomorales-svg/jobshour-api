<?php

$providers = [
    App\Providers\AppServiceProvider::class,
    Illuminate\Broadcasting\BroadcastServiceProvider::class,
];

if (filter_var(env('TELESCOPE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
    $providers[] = App\Providers\TelescopeServiceProvider::class;
}

return $providers;

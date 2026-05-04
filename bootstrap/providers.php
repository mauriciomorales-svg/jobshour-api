<?php

$providers = [
    App\Providers\AppServiceProvider::class,
    Illuminate\Broadcasting\BroadcastServiceProvider::class,
];

$telescopeFlag = filter_var(env('TELESCOPE_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($telescopeFlag === null) {
    $telescopeFlag = env('APP_ENV', 'production') === 'local';
}

if ($telescopeFlag) {
    $providers[] = App\Providers\TelescopeServiceProvider::class;
}

return $providers;

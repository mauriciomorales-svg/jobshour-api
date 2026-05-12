<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * Acceso restringido a los IDs definidos en config/admin.php.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if (! $user) return false;
            $adminIds = config('admin.user_ids', [21]);
            return in_array($user->id, $adminIds, true);
        });
    }
}

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
     * This gate determines who can access Horizon in non-local environments.
     * In production, restrict access to authorized admin users only.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            // Allow in local environment for development
            if (app()->environment('local')) {
                return true;
            }

            // In production, check against authorized admin emails
            $authorizedEmails = explode(',', config('horizon.admins', ''));

            return $user && in_array($user->email, array_filter($authorizedEmails));
        });
    }
}

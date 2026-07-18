<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // .env.example ships APP_DEBUG=true for local dev; fail loudly rather
        // than let a production deploy silently leak stack traces if that
        // default is ever carried into a production .env.
        if ($this->app->environment('production') && config('app.debug')) {
            throw new \RuntimeException('APP_DEBUG must be false in production.');
        }
    }
}

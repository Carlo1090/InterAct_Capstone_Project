<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Backs bootstrap/app.php's throttleApi() call — without this the
        // 'api' limiter falls back to Laravel's built-in default, which is
        // still fine, but keeping it explicit here documents the chosen
        // number and keys it per-user (not just per-IP), so a shared-NAT
        // office network can't get one account's abuse throttled onto
        // everyone else behind that IP.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}

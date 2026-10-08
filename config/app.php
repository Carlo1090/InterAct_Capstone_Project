<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Where the Vue SPA is served from. Anything that has to bounce a browser
    | back out of the API and into the app — the Google OAuth callback, and
    | Breeze's stock email-verification redirect — builds its target from this.
    |
    | It was referenced as config('app.frontend_url') by
    | Auth\VerifyEmailController without ever being defined, so that redirect
    | resolved to a bare "/dashboard" on no host at all.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Every user of this system is in the Philippines (UTC+8), and the app is
    | full of "what day is it" logic that a UTC server gets wrong for them: the
    | journal calendar's today/future boundary, the missing-entry reminder's
    | per-student hour, "this week" on the coordinator and student dashboards,
    | and the rolling end of ResolvesStudentEnrollment::ojtRange(). On a UTC
    | server, anything before 08:00 Manila still reads as YESTERDAY — so a
    | student writing a journal at 7am is offered the wrong date.
    |
    | THE DEFAULT IS Asia/Manila, CHANGED 2026-10-08 after that symptom was
    | reported for real: the write screen offered YESTERDAY and refused today.
    | It used to default to UTC, on the reasoning that deployments set
    | APP_TIMEZONE explicitly and the test suite wants a fixed UTC baseline. The
    | first half of that is the problem — it makes correctness depend on an
    | environment variable REMEMBERED in two places (the local .env, which did
    | not have it, and the Render dashboard, where a Blueprint value only lands
    | if the blueprint was actually synced). A missing variable then produces a
    | silent, eight-hours-a-day wrong answer rather than a loud failure, which
    | is the same trap documented for the mobile app's API_BASE_URL fallback: a
    | default that degrades to the CORRECT value for every real user beats one
    | that degrades to a plausible-looking outage.
    |
    | The test suite's UTC baseline is preserved exactly, by pinning
    | APP_TIMEZONE=UTC in phpunit.xml instead of relying on this default. Both
    | halves must move together — dropping that pin silently reinterprets every
    | Carbon::setTestNow date in the suite as Manila time.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];

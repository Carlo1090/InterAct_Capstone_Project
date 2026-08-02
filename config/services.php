<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Google OAuth — used ONLY to (a) prove a user owns an email address and
     * (b) sign in an account whose address was already proven that way. It
     * never creates accounts and never assigns roles; see
     * App\Http\Controllers\Auth\GoogleController.
     *
     * The redirect URI must match one registered on the OAuth client exactly,
     * including scheme, host and port.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

    /*
     * Shared secret for POST|GET /api/cron/run, which lets an external scheduler
     * run the jobs in routes/console.php on a host with no cron. Leaving it
     * unset disables the endpoint (it 404s) rather than leaving it open — see
     * App\Http\Controllers\CronController.
     */
    'cron' => [
        'secret' => env('CRON_SECRET'),
    ],

];

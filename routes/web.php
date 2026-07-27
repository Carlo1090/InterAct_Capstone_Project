<?php

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

/*
 * Google OAuth. These are WEB routes, not api/*, because all three are
 * top-level browser navigations rather than XHR — the SPA just sets
 * window.location.href, and Google redirects the browser back here.
 *
 * The callback is deliberately NOT behind auth: it is authenticated by the
 * encrypted state parameter plus a nonce cookie, which is what lets the same
 * endpoint serve both the signed-in "verify" flow and the signed-out "login"
 * flow. One callback also means only ONE redirect URI to register in Google
 * Cloud. Throttled because these are authentication endpoints and the api
 * group's rate limiter does not cover them.
 */
Route::middleware('throttle:10,1')->group(function () {
    Route::get('auth/google/verify', [GoogleController::class, 'redirectToVerify'])
        ->middleware('auth')
        ->name('google.verify');

    // No `guest` middleware on purpose — it redirects an already-authenticated
    // user to "/", which on the API origin is Laravel's root JSON response, a
    // dead end on a different host from the SPA. The controller handles the
    // already-signed-in case by sending them into the app instead.
    Route::get('auth/google/login', [GoogleController::class, 'redirectToLogin'])
        ->name('google.login');

    Route::get('auth/google/callback', [GoogleController::class, 'callback'])
        ->name('google.callback');
});

require __DIR__.'/auth.php';

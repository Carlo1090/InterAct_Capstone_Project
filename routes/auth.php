<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

/*
 * There is deliberately NO /register route. Every account in InternTrack is
 * staff-provisioned: an admin creates coordinators, a coordinator creates
 * students and supervisors. Breeze's self-service registration shipped here by
 * default and created an ACTIVE `role: student` account for anyone who could
 * reach the endpoint, unthrottled, which on a public deployment means a
 * stranger can land in a coordinator's info-sheet queue. The SPA never
 * referenced it. Removing the route also stops the `Registered` event firing,
 * which is what previously made adding MustVerifyEmail to User unsafe.
 *
 * See RegistrationClosedTest — it asserts the endpoint stays gone.
 */

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

/*
 * Throttled, unlike the rest of this file's guest routes. The api group's
 * limiter does not cover web routes, and the password broker's own throttle
 * (config/auth.php, 60s) only rate-limits repeats for the SAME address — it
 * does nothing about a caller walking a list of addresses, which both sends
 * real mail to real students and reads back whether each address is on file.
 * /login has its own per-identifier limiter inside LoginRequest already.
 */
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest', 'throttle:6,1'])
    ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware(['guest', 'throttle:6,1'])
    ->name('password.store');

Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('verification.send');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

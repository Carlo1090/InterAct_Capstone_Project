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

/*
 * No `guest` middleware here — and that is a deliberate fix, not an
 * oversight. Laravel's stock RedirectIfAuthenticated middleware answers an
 * already-authenticated request with a 302 to "/", which on this API is the
 * plain `['Laravel' => version]` JSON root (routes/web.php). Axios follows
 * that redirect transparently, so `POST /auth/login` "succeeds" with that
 * body instead of `{user: ...}`. auth.ts then sets `this.user = undefined`,
 * `roleRedirect(null)` resolves to '/login', and LoginPage.vue pushes to the
 * page it is already on — the button spinner clears and the user is dumped
 * back on a blank login form with no error, which reads as "login is stuck."
 *
 * This bites often in practice: SESSION_LIFETIME is 120 minutes, and the SPA
 * only ever calls fetchUser() on `requiresAuth` routes, so landing back on
 * the public /login page (a bookmark, a back-button, a second tab) with a
 * still-valid session behind it is completely invisible to the SPA — exactly
 * the same "session outlived what the SPA thought" case already handled in
 * GoogleController::redirectToLogin() for the Google button. This is that
 * same fix applied to the plain username/password form: AuthenticatedSessionController::store()
 * doesn't assume a guest, so removing `guest` here just lets Auth::attempt()
 * authenticate fresh credentials and session()->regenerate() cleanly replace
 * whatever was there before — correct for the shared MDC computer-lab
 * machines this app runs on, where the next student logging in should not be
 * silently blocked by the previous one's session.
 */
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->name('login');

/*
 * Throttled, unlike the rest of this file's guest routes. The api group's
 * limiter does not cover web routes, and the password broker's own throttle
 * (config/auth.php, 60s) only rate-limits repeats for the SAME address — it
 * does nothing about a caller walking a list of addresses, which both sends
 * real mail to real students and reads back whether each address is on file.
 * /login has its own per-identifier limiter inside LoginRequest already.
 *
 * Also no `guest` here, for the identical reason as /login above — neither
 * controller reads or assumes the current auth state, so an authenticated
 * visitor hitting either page (the same stale-session scenario) gets a real
 * answer instead of a silently-followed redirect to the JSON root.
 */
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['throttle:6,1'])
    ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware(['throttle:6,1'])
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

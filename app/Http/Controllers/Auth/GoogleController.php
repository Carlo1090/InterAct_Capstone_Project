<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google OAuth, used for exactly two things:
 *
 *   intent=verify — prove the signed-in user owns a Gmail address, and store it
 *                   on their account with email_verified_at stamped.
 *   intent=login  — sign in an account whose address was ALREADY proven that
 *                   way ("link-only" sign-in).
 *
 * It NEVER creates an account and NEVER assigns a role. Accounts here are
 * staff-provisioned (a coordinator creates the student, sets their program and
 * batch), so an auto-created OAuth user would have no valid role and no
 * enrollment. An unrecognised Google address is rejected, not registered.
 *
 * The guard that makes link-only sign-in safe is the pairing of BOTH conditions:
 * the address matches AND email_verified_at is non-null. Since this controller
 * is the only thing that ever sets that column, a non-null value means Google
 * itself established the binding — so an address merely typed in by an admin or
 * a student can never be signed into. Editing the email clears the stamp (see
 * ProfileController::update), which is also how a user unlinks Google.
 */
class GoogleController extends Controller
{
    private const NONCE_COOKIE = 'google_oauth_nonce';

    private const STATE_TTL_MINUTES = 10;

    /** Verify (and set) the signed-in user's email address. */
    public function redirectToVerify(Request $request): RedirectResponse
    {
        return $this->startFlow('verify', $request->user()->id);
    }

    /** Sign in with a previously verified Google address. */
    public function redirectToLogin(Request $request): RedirectResponse
    {
        // Already signed in — typically a session that outlived what the SPA
        // thought, so the user was looking at the login page with a live
        // session behind it. This route deliberately does NOT use the `guest`
        // middleware: that redirects to "/", which on the API origin is
        // Laravel's root JSON response, stranding the user on the wrong host.
        // Send them into the app instead and let the router place them by role.
        Log::info('Google OAuth: login entry point hit', [
            'already_authenticated' => $request->user() !== null,
        ]);

        if ($request->user()) {
            return $this->toFrontend($this->dashboardPathFor($request->user()));
        }

        return $this->startFlow('login', null);
    }

    public function callback(Request $request): RedirectResponse
    {
        Log::info('Google OAuth: callback hit', [
            'has_state' => $request->query('state') !== null,
            'has_code' => $request->query('code') !== null,
            'google_error' => $request->query('error'),
        ]);

        $state = $this->decodeState($request->query('state'));

        if ($state === null) {
            return $this->fail('login', 'invalid_state');
        }

        // Double-submit check. Socialite's own CSRF protection lives in the
        // session and is given up by stateless(); this replaces it, and unlike
        // the session it cannot be lost on the round trip back from Google once
        // the SPA and API sit on different domains.
        if (! hash_equals((string) $request->cookie(self::NONCE_COOKIE), $state['nonce'])) {
            return $this->fail($state['intent'], 'invalid_state');
        }

        Cookie::expire(self::NONCE_COOKIE);

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            // The user only ever sees a generic message, but swallowing the real
            // reason makes this undebuggable — a bad/expired code, a mismatched
            // redirect URI, and a host that cannot verify Google's TLS
            // certificate all look identical from the outside.
            Log::warning('Google OAuth token exchange failed', [
                'intent' => $state['intent'],
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->fail($state['intent'], 'google_failed');
        }

        $email = Str::lower(trim((string) $googleUser->getEmail()));

        if ($email === '' || ! $this->googleReportsVerified($googleUser)) {
            return $this->fail($state['intent'], 'unverified_google_email');
        }

        return $state['intent'] === 'verify'
            ? $this->completeVerification($state, $email)
            : $this->completeLogin($request, $email);
    }

    /**
     * PART 4 — bind the address to the account that started the flow.
     */
    private function completeVerification(array $state, string $email): RedirectResponse
    {
        $user = User::find($state['user_id']);

        if (! $user) {
            return $this->fail('verify', 'invalid_state');
        }

        // users.email is unique. Claiming an address already on another account
        // would either steal their sign-in or blow up on the constraint.
        $takenByAnother = $this->userByEmail($email)?->isNot($user) ?? false;

        if ($takenByAnother) {
            return $this->fail('verify', 'email_taken', $user->role);
        }

        $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();

        $this->log($user, 'Email Verified', "{$user->name} verified {$email} with Google");

        return $this->toFrontend("/{$user->role}/dashboard", ['email_verified' => 1]);
    }

    /**
     * PART 5 — link-only sign-in.
     */
    private function completeLogin(Request $request, string $email): RedirectResponse
    {
        $user = $this->userByEmail($email);

        // Both halves matter: the address must match AND have been verified
        // through this controller. Never fall back to creating an account.
        if (! $user || $user->email_verified_at === null) {
            return $this->fail('login', 'not_linked');
        }

        if (! $user->is_active) {
            return $this->fail('login', 'deactivated');
        }

        Auth::login($user);
        // Against session fixation — the pre-login session id must not survive.
        $request->session()->regenerate();

        $this->log($user, 'Logged In', "{$user->name} signed in with Google");

        // Straight to their dashboard, NOT "/". The SPA's "/" route is an
        // unconditional `redirect: '/login'`, and its router guard only calls
        // fetchUser() for routes marked requiresAuth — so landing on "/" with a
        // perfectly good session dumped the user back on the login page, where
        // clicking Login merely rode the session they already had.
        return $this->toFrontend($this->dashboardPathFor($user));
    }

    /**
     * Where a signed-in user belongs in the SPA. Mirrors roleRedirect() in
     * web/src/router/index.ts; these routes are requiresAuth, so the router
     * guard resolves the session on arrival.
     */
    private function dashboardPathFor(User $user): string
    {
        return "/{$user->role}/dashboard";
    }

    private function startFlow(string $intent, ?int $userId): RedirectResponse
    {
        // Fail INSIDE the app rather than handing the browser to Google with an
        // empty client_id — Socialite will happily build that URL, and Google
        // answers with its own "Access blocked: Authorisation error / Missing
        // required parameter: client_id" page, which strands the user outside
        // InternTrack with no way back.
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            return $this->fail($intent, 'not_configured');
        }

        $nonce = Str::random(40);

        $state = Crypt::encryptString(json_encode([
            'intent' => $intent,
            'user_id' => $userId,
            'nonce' => $nonce,
            'expires_at' => now()->addMinutes(self::STATE_TTL_MINUTES)->timestamp,
        ]));

        // SameSite=Lax so the browser still sends it on the top-level GET
        // redirect back from accounts.google.com; httpOnly since only the
        // server ever compares it.
        Cookie::queue(cookie(
            name: self::NONCE_COOKIE,
            value: $nonce,
            minutes: self::STATE_TTL_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        ));

        return Socialite::driver('google')
            ->stateless()
            ->with([
                'state' => $state,
                // ALWAYS show Google's account chooser, even when exactly one
                // account is signed in on the device. Without this, Google
                // silently auto-selects that account — so on a shared machine
                // (a computer lab, a borrowed laptop) one student clicking
                // "Sign in with Google" would be signed straight in as
                // whoever last used Chrome, with no password and no prompt.
                //
                // The verify flow needs it just as much: silent selection
                // there would bind someone else's Gmail onto your account,
                // and since a verified address is what authorises Google
                // sign-in, that hands them a way in permanently.
                //
                // Deliberately Google's chooser rather than a confirmation
                // screen of our own: the account state lives on Google's
                // domain, so only Google can truthfully say which accounts
                // are available and let the user switch or add one.
                'prompt' => 'select_account',
            ])
            ->redirect();
    }

    /**
     * @return array{intent: string, user_id: int|null, nonce: string}|null
     */
    private function decodeState(?string $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $state = json_decode(Crypt::decryptString($raw), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // Tampered, or encrypted with a different APP_KEY.
            return null;
        }

        if (! is_array($state) || ! in_array($state['intent'] ?? null, ['verify', 'login'], true)) {
            return null;
        }

        if (! isset($state['nonce'], $state['expires_at']) || Carbon::now()->timestamp > $state['expires_at']) {
            return null;
        }

        return [
            'intent' => $state['intent'],
            'user_id' => $state['user_id'] ?? null,
            'nonce' => (string) $state['nonce'],
        ];
    }

    /**
     * Google's OIDC userinfo returns email_verified; older payloads used
     * verified_email. An address Google itself has not confirmed proves nothing.
     */
    private function googleReportsVerified(object $googleUser): bool
    {
        $raw = (array) ($googleUser->user ?? []);
        $flag = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * LOWER() rather than a plain where(): MySQL's default collation is
     * case-insensitive but SQLite's is not, so a stored "Foo@Gmail.com" would
     * silently fail to match Google's lowercase address on one engine and match
     * on the other. Bound parameter, not interpolated.
     */
    private function userByEmail(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [$email])->first();
    }

    /**
     * SystemLog::record() reads the actor off request()->user(), which is not
     * reliable here — the callback route is not behind auth middleware. Write
     * the row explicitly so the Activity Log attributes it correctly.
     */
    private function log(User $user, string $action, string $description): void
    {
        SystemLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
        ]);
    }

    private function fail(string $intent, string $code, ?string $role = null): RedirectResponse
    {
        if ($intent === 'verify') {
            $role ??= request()->user()?->role;

            return $this->toFrontend($role ? "/{$role}/dashboard" : '/', ['email_error' => $code]);
        }

        return $this->toFrontend('/login', ['google_error' => $code]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function toFrontend(string $path, array $query = []): RedirectResponse
    {
        $url = rtrim((string) config('app.frontend_url'), '/').$path;

        return redirect()->away($query === [] ? $url : $url.'?'.http_build_query($query));
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const NONCE = 'a-forty-character-nonce-for-the-test-abc';

    private function mockGoogleUser(string $email, bool $verified = true): void
    {
        $googleUser = (new SocialiteUser)
            ->setRaw(['email_verified' => $verified])
            ->map(['email' => $email, 'name' => 'Google Person']);

        Socialite::shouldReceive('driver->stateless->user')->andReturn($googleUser);
    }

    private function state(string $intent, ?int $userId, ?string $nonce = null, ?int $expiresAt = null): string
    {
        return Crypt::encryptString(json_encode([
            'intent' => $intent,
            'user_id' => $userId,
            'nonce' => $nonce ?? self::NONCE,
            'expires_at' => $expiresAt ?? now()->addMinutes(10)->timestamp,
        ]));
    }

    private function hitCallback(string $state, ?string $cookieNonce = null): TestResponse
    {
        return $this->withCookie('google_oauth_nonce', $cookieNonce ?? self::NONCE)
            ->get('/auth/google/callback?code=fake-auth-code&state='.urlencode($state));
    }

    // ---------------------------------------------------------------- verify

    public function test_verifying_stores_the_google_address_and_stamps_it_verified(): void
    {
        $user = User::factory()->create(['role' => 'student', 'email' => null, 'email_verified_at' => null]);
        $this->mockGoogleUser('renz.corvera@gmail.com');

        $response = $this->hitCallback($this->state('verify', $user->id));

        $response->assertRedirectContains('/student/dashboard?email_verified=1');

        $user->refresh();
        $this->assertSame('renz.corvera@gmail.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_an_address_google_itself_reports_as_unverified_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'student', 'email' => null, 'email_verified_at' => null]);
        $this->mockGoogleUser('sketchy@gmail.com', verified: false);

        $this->hitCallback($this->state('verify', $user->id))
            ->assertRedirectContains('email_error=unverified_google_email');

        $user->refresh();
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * users.email is unique — claiming someone else's address would either
     * steal their sign-in or blow up on the constraint.
     */
    public function test_an_address_already_owned_by_another_account_is_refused(): void
    {
        $owner = User::factory()->create(['email' => 'taken@gmail.com']);
        $claimant = User::factory()->create(['role' => 'student', 'email' => null, 'email_verified_at' => null]);
        $this->mockGoogleUser('taken@gmail.com');

        $this->hitCallback($this->state('verify', $claimant->id))
            ->assertRedirectContains('email_error=email_taken');

        $this->assertNull($claimant->refresh()->email);
        $this->assertSame('taken@gmail.com', $owner->refresh()->email);
    }

    // ----------------------------------------------------------------- login

    public function test_a_verified_active_account_can_sign_in_with_google(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'email' => 'renz@gmail.com',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->mockGoogleUser('renz@gmail.com');

        $response = $this->hitCallback($this->state('login', null));

        $this->assertAuthenticatedAs($user);

        // Their dashboard, NOT "/". The SPA's "/" is an unconditional redirect to
        // /login and its guard only resolves the session for requiresAuth routes,
        // so landing on "/" threw away a perfectly good sign-in.
        $response->assertRedirectContains('/student/dashboard');
        $this->assertStringNotContainsString('/login', (string) $response->headers->get('Location'));
    }

    /**
     * THE load-bearing guard. An address that was merely typed in by an admin or
     * a student — never proven through Google — must not be signable-into, or a
     * typo would hand a stranger someone's account.
     */
    public function test_an_unverified_address_cannot_be_signed_into(): void
    {
        User::factory()->create([
            'email' => 'typo@gmail.com',
            'email_verified_at' => null,
            'is_active' => true,
        ]);
        $this->mockGoogleUser('typo@gmail.com');

        $this->hitCallback($this->state('login', null))
            ->assertRedirectContains('/login?google_error=not_linked');

        $this->assertGuest();
    }

    public function test_an_unknown_google_address_creates_nothing(): void
    {
        $before = User::count();
        $this->mockGoogleUser('a.total.stranger@gmail.com');

        $this->hitCallback($this->state('login', null))
            ->assertRedirectContains('google_error=not_linked');

        $this->assertGuest();
        $this->assertSame($before, User::count());
    }

    public function test_a_deactivated_account_cannot_sign_in_with_google(): void
    {
        User::factory()->create([
            'email' => 'gone@gmail.com',
            'email_verified_at' => now(),
            'is_active' => false,
        ]);
        $this->mockGoogleUser('gone@gmail.com');

        $this->hitCallback($this->state('login', null))
            ->assertRedirectContains('google_error=deactivated');

        $this->assertGuest();
    }

    // ----------------------------------------------------------- state / CSRF

    public function test_a_tampered_state_is_rejected(): void
    {
        User::factory()->create(['email' => 'renz@gmail.com', 'email_verified_at' => now()]);
        $this->mockGoogleUser('renz@gmail.com');

        $this->hitCallback('not-actually-encrypted')
            ->assertRedirectContains('google_error=invalid_state');

        $this->assertGuest();
    }

    public function test_an_expired_state_is_rejected(): void
    {
        User::factory()->create(['email' => 'renz@gmail.com', 'email_verified_at' => now()]);
        $this->mockGoogleUser('renz@gmail.com');

        $expired = $this->state('login', null, expiresAt: now()->subMinute()->timestamp);

        $this->hitCallback($expired)->assertRedirectContains('google_error=invalid_state');
        $this->assertGuest();
    }

    /**
     * The nonce cookie is what replaces the CSRF protection stateless() gives
     * up — without it an attacker could complete a flow in a victim's browser.
     */
    public function test_a_mismatched_nonce_cookie_is_rejected(): void
    {
        User::factory()->create(['email' => 'renz@gmail.com', 'email_verified_at' => now()]);
        $this->mockGoogleUser('renz@gmail.com');

        $this->hitCallback($this->state('login', null), cookieNonce: 'a-different-nonce-entirely')
            ->assertRedirectContains('google_error=invalid_state');

        $this->assertGuest();
    }

    // ------------------------------------------------------ unlink on change

    public function test_changing_the_email_clears_verification_and_revokes_google_sign_in(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'email' => 'old@gmail.com',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->putJson('/api/profile', [
            'name' => $user->name,
            'username' => $user->username,
            'email' => 'new@gmail.com',
        ])->assertOk();

        $this->assertNull($user->refresh()->email_verified_at);

        // The old address is now unknown, and the new one is unverified —
        // neither can sign in until it is verified through Google again.
        $this->mockGoogleUser('new@gmail.com');
        $this->app['auth']->guard('web')->logout();

        $this->hitCallback($this->state('login', null))
            ->assertRedirectContains('google_error=not_linked');
    }

    // ---------------------------------------------------------- route guards

    public function test_the_verify_entry_point_requires_authentication(): void
    {
        $this->get('/auth/google/verify')->assertRedirect('/login');
    }

    /**
     * With no credentials configured, Socialite would still happily build a
     * redirect carrying an empty client_id, and Google answers that with its own
     * "Access blocked: Authorisation error" page — stranding the user outside
     * the app entirely. Fail inside InternTrack instead.
     */
    public function test_missing_google_credentials_fail_inside_the_app_rather_than_at_google(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $response = $this->get('/auth/google/login');

        $response->assertRedirectContains('google_error=not_configured');
        $this->assertStringNotContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    /**
     * A session that outlives what the SPA thinks — the user is looking at the
     * login page with a live session behind it and clicks "Sign in with Google".
     * The `guest` middleware used to bounce them to "/", which on the API origin
     * is Laravel's root JSON response: a dead end on the wrong host.
     */
    public function test_an_already_signed_in_user_is_sent_into_the_app_not_the_api_root(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($user)->get('/auth/google/login');

        $response->assertRedirect(config('app.frontend_url').'/student/dashboard');
        $this->assertStringNotContainsString('{"Laravel"', (string) $response->getContent());
    }

    public function test_a_configured_login_entry_point_redirects_to_google(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost:8000/auth/google/callback',
        ]);

        $this->get('/auth/google/login')
            ->assertRedirectContains('accounts.google.com')
            ->assertRedirectContains('client_id=test-client-id')
            // The encrypted state we mint ourselves, since stateless() drops
            // Socialite's own session-backed state.
            ->assertRedirectContains('state=');
    }

    /**
     * Shared devices are the norm here (computer labs, borrowed laptops). With
     * a single Google account signed into the browser, Google auto-selects it
     * silently — so one student clicking "Sign in with Google" on another's
     * machine would be signed straight in as them, no password, no prompt.
     * prompt=select_account forces the chooser every time.
     */
    public function test_both_flows_force_googles_account_chooser(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost:8000/auth/google/callback',
        ]);

        $this->get('/auth/google/login')
            ->assertRedirectContains('prompt=select_account');

        $this->actingAs(User::factory()->create(['role' => 'student']))
            ->get('/auth/google/verify')
            ->assertRedirectContains('prompt=select_account');
    }

    /**
     * The Edit Profile badge and the dashboard banner both key off this field,
     * so /api/user must keep exposing it.
     */
    public function test_the_user_endpoint_exposes_email_verified_at(): void
    {
        $user = User::factory()->create(['role' => 'student', 'email_verified_at' => null]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email_verified_at', null);

        // Query-builder update, not $user->save(): the /api/user route decorates
        // the request's model instance with virtual student_gated/student_paused
        // attributes, and saving that same instance would try to persist them as
        // real columns.
        User::whereKey($user->id)->update(['email_verified_at' => now()]);

        // Sanctum::actingAs pins one model instance for the whole test, so it
        // would keep serving the pre-update copy — re-act as a freshly loaded one.
        Sanctum::actingAs(User::findOrFail($user->id), ['*']);

        $this->assertNotNull($this->getJson('/api/user')->json('email_verified_at'));
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The student's own way back in when the welcome email never arrived.
 *
 * Every endpoint here already worked; what did not was that the SPA had no
 * page for any of it and the API answered these routes in HTML. A student who
 * never got their credentials had NO self-service option at all and had to
 * find their coordinator, so these tests pin the two halves that made the flow
 * unreachable rather than merely the happy path.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'student',
            'username' => 'ID-1001',
            'email' => 'lost@example.com',
            'password' => 'original-password',
            ...$attributes,
        ]);
    }

    /**
     * The link the email carries must point at the SPA route that exists, not
     * at the API. AppServiceProvider::boot() overrides it to FRONTEND_URL, and
     * the path has to stay '/password-reset/{token}' — the SPA owns that path
     * as a page, deliberately distinct from the API's own POST
     * '/reset-password' so the deployed rewrite cannot swallow the page load.
     */
    public function test_the_emailed_link_points_at_the_spa_reset_page(): void
    {
        Notification::fake();

        $student = $this->student();

        $this->postJson('/forgot-password', ['email' => 'lost@example.com'])->assertOk();

        Notification::assertSentTo($student, ResetPassword::class, function (ResetPassword $notification) use ($student) {
            $url = call_user_func(ResetPassword::$createUrlCallback, $student, $notification->token);

            $this->assertStringStartsWith(config('app.frontend_url').'/password-reset/', $url);
            // The broker keys password_reset_tokens by email, so the token
            // alone identifies nothing — the page has to get both back.
            $this->assertStringContainsString('email=lost@example.com', $url);

            return true;
        });
    }

    public function test_a_student_can_reset_their_password_and_sign_in_with_the_new_one(): void
    {
        Notification::fake();

        $student = $this->student();
        $token = Password::broker()->createToken($student);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'lost@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $student->fresh()->password));

        $this->postJson('/login', ['login' => 'ID-1001', 'password' => 'brand-new-password'])->assertOk();
    }

    public function test_a_used_token_cannot_be_replayed(): void
    {
        Notification::fake();

        $student = $this->student();
        $token = Password::broker()->createToken($student);

        $payload = [
            'token' => $token,
            'email' => 'lost@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ];

        $this->postJson('/reset-password', $payload)->assertOk();
        $this->postJson('/reset-password', $payload)->assertStatus(422);
    }

    /**
     * THE FIX THAT MADE THE PAGES POSSIBLE. bootstrap/app.php's
     * shouldRenderJsonWhen() fully REPLACES Laravel's expectsJson() check, and
     * listed only api/*. These auth routes are web routes, so every failure
     * came back as a 302 HTML redirect even for an XHR asking for JSON —
     * leaving a reset form with no way to show "we can't find a user with that
     * email address" or that a token had expired.
     */
    public function test_reset_failures_answer_in_json_rather_than_redirecting(): void
    {
        $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.com']);
        $unknown->assertStatus(422);
        $unknown->assertJsonValidationErrors('email');

        $badToken = $this->postJson('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'nobody@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);
        $badToken->assertStatus(422);
        $badToken->assertJsonValidationErrors('email');
    }

    /**
     * The same fix, on the path a locked-out student actually walks first.
     * LoginRequest rejects a deactivated account with its own reason, but the
     * message could never reach the SPA, so LoginPage showed "Invalid
     * credentials" instead — sending that student to ask for a credentials
     * resend, which cannot possibly help them.
     */
    public function test_a_deactivated_account_is_told_why_in_json(): void
    {
        $this->student(['is_active' => false]);

        $response = $this->postJson('/login', ['login' => 'ID-1001', 'password' => 'original-password']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('login');
        $this->assertStringContainsString('deactivated', $response->json('errors.login.0'));
    }

    public function test_a_wrong_password_answers_in_json_too(): void
    {
        $this->student();

        $this->postJson('/login', ['login' => 'ID-1001', 'password' => 'not-the-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    /**
     * Unthrottled, this endpoint both mails real students and reports back
     * whether each address is on file. The broker's own throttle only covers
     * repeats of the SAME address, so it does nothing about a caller walking a
     * list.
     */
    public function test_the_forgot_password_endpoint_is_throttled(): void
    {
        $this->student();

        // The limiter is 6/min; the 7th must be refused.
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postJson('/forgot-password', ['email' => "probe{$attempt}@example.com"]);
        }

        $this->postJson('/forgot-password', ['email' => 'probe7@example.com'])->assertStatus(429);
    }
}

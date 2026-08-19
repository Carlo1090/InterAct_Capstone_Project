<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Token-issuing sibling of LoginRequest, for the mobile app. Mirrors its
 * identifier normalization and rate limiting, but deliberately does NOT call
 * Auth::attempt() — that starts a web session, which this stateless
 * bearer-token endpoint has no use for.
 */
class MobileLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'login' => $this->input('login') ?: ($this->input('username') ?: $this->input('email')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Resolve the user for these credentials, or throw the same validation
     * error shape LoginRequest uses on any failure (unknown login, wrong
     * password, or a deactivated account).
     *
     * @throws ValidationException
     */
    public function resolveUser(): User
    {
        $this->ensureIsNotRateLimited();

        $login = (string) $this->input('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::where($field, $login)->first();

        if (! $user || ! Hash::check((string) $this->input('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => 'This account has been deactivated.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('login')).'|'.$this->ip());
    }
}

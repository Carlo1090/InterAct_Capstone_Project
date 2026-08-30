<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\AuthUserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        // Flush BEFORE authenticating, not after. This route has no `guest`
        // middleware (see routes/auth.php) so it can also fire with a session
        // already carrying someone else's data — a shared MDC lab machine, or
        // simply the login page loaded before that older session expired.
        // migrate()/regenerate() only ever rotate the session ID; they never
        // touch $attributes, so the PREVIOUS user's `password_hash_web` key
        // (Illuminate\Session\Middleware\AuthenticateSession, enabled via
        // config/sanctum.php's authenticate_session) survives straight into
        // the new session. The very next authenticated request then hashes
        // the NEWLY logged-in user's password, compares it against that
        // leftover hash, finds a mismatch (different users, different
        // bcrypt hashes), and — reading it as tampering — calls
        // session()->flush() + throws, which is what a plain 401 with no
        // error message ever showed for. Confirmed by tracing the exact SQL
        // writes against the `sessions` table: the row went from a correct
        // `login_web_*` payload to an empty one within milliseconds, always
        // on the first request after the switch. Flushing first denies that
        // stale key a session to survive in.
        $request->session()->flush();

        $request->authenticate();

        $request->session()->regenerate();

        // The SAME payload GET /api/user returns, so the SPA can take the user
        // straight from this response instead of immediately re-fetching it —
        // that follow-up call used to be a third blocking round trip on every
        // login. The two must never drift, hence the shared builder.
        return response()->json([
            'user' => AuthUserPayload::build($request->user()),
        ]);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }
}

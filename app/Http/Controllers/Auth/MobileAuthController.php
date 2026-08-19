<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MobileLoginRequest;
use App\Models\SystemLog;
use App\Support\AuthUserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Bearer-token auth for the mobile app, distinct from the web SPA's
 * session-cookie flow in AuthenticatedSessionController. Sanctum's
 * `auth:sanctum` guard already accepts a bearer token ahead of a stateful
 * cookie check, so once a token is issued here every existing
 * role:student/infosheet.approved route works unchanged.
 */
class MobileAuthController extends Controller
{
    public function store(MobileLoginRequest $request): JsonResponse
    {
        $user = $request->resolveUser();

        // Mobile is student-only scope — reject any other role outright,
        // even with correct credentials, rather than issue a token that
        // would then 403 on every subsequent request.
        if ($user->role !== 'student') {
            throw ValidationException::withMessages([
                'login' => 'This app is for students only. Please use the web portal.',
            ]);
        }

        $token = $user->createToken('mobile-app', ['*'])->plainTextToken;

        // Not SystemLog::record() here — it reads request()->user(), which is
        // still null at this point since this endpoint authenticates by
        // resolving credentials directly rather than via Auth::login().
        SystemLog::create([
            'user_id' => $user->id,
            'action' => 'Logged In',
            'description' => "{$user->name} logged in (mobile)",
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'token' => $token,
            // Same builder GET /api/user and POST /login use, so the mobile
            // app's login response already carries student_gated/
            // student_paused — matches AuthUserPayload's whole point: one
            // shape regardless of how the caller authenticated.
            'user' => AuthUserPayload::build($user),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        SystemLog::record('Logged Out', "{$request->user()?->name} logged out (mobile)");

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}

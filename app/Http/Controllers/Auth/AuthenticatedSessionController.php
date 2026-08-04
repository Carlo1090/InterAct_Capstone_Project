<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\SystemLog;
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
        $request->authenticate();
        $request->session()->regenerate();

        SystemLog::record('Logged In', "{$request->user()->name} logged in");

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
        // Logged before logout — SystemLog::record() reads request()->user(),
        // which is gone once the guard logs out.
        SystemLog::record('Logged Out', "{$request->user()?->name} logged out");

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }
}

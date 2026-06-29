<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,coordinator')
     * for routes that should be reachable by more than one role.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'You are not authorized to perform this action.'], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Student Information Sheet is the enrollment gateway. Until a student's
 * sheet is approved (i.e. the coordinator has accepted it and thereby enrolled
 * them), they may only reach the info-sheet routes + account essentials. This
 * blocks every other student endpoint at the API level so the gate cannot be
 * bypassed by calling the API directly, mirroring the frontend route guard.
 */
class EnsureInfoSheetApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isStudent() && $user->isInfoSheetGated()) {
            abort(403, 'Complete your Student Information Sheet and have it approved by your coordinator before accessing this.');
        }

        return $next($request);
    }
}

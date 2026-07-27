<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureInfoSheetApproved;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The deployed SPA reaches this API through Vercel's rewrite proxy, so
         * every request arrives from an edge IP rather than the real client.
         * Laravel trusts NO proxies by default (TrustProxies::handle() calls
         * setTrustedProxies([]) unless configured), which means it ignores
         * X-Forwarded-For and reports the proxy's address as $request->ip().
         *
         * That matters because rate limiting keys off the IP for unauthenticated
         * callers: the 'api' limiter in AppServiceProvider falls back to it, and
         * the throttle:10,1 on the Google OAuth routes in routes/web.php uses it
         * outright. Untrusted, all visitors share ONE bucket.
         *
         * Trade-off accepted knowingly: '*' means a caller could spoof
         * X-Forwarded-For to dodge throttling. The alternative is a fixed list
         * of edge IPs, which Vercel does not publish as a stable set. For this
         * deployment an accidental shared-bucket lockout is the likelier harm.
         */
        $middleware->trustProxies(at: '*');

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // EnsureFrontendRequestsAreStateful runs first (prepended above) so
        // the session is already resolved by the time this appended
        // throttle checks $request->user() in the 'api' limiter defined in
        // AppServiceProvider::boot().
        $middleware->throttleApi();

        $middleware->alias([
            'role' => EnsureRole::class,
            'infosheet.approved' => EnsureInfoSheetApproved::class,
            'verified' => EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

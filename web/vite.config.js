import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backendUrl = env.VITE_BACKEND_URL || 'http://localhost:8000'

  return {
    plugins: [vue(), tailwindcss()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      // Listen on every interface, not just localhost, so a phone on the same
      // Wi-Fi (or a tunnel like cloudflared) can reach the dev server. Needed
      // to test the DTR camera scanner and geolocation on a real device —
      // neither can be exercised from a desktop browser.
      host: true,
      port: 5173,
      // Fail loudly if 5173 is taken instead of sliding to 5174. That silent
      // fallback produces a genuinely misleading bug: the port is no longer in
      // SANCTUM_STATEFUL_DOMAINS, so POST /auth/login returns 200 with the user
      // while the very next GET /api/user returns 401, and the SPA reports
      // "Invalid credentials" on a perfectly good password.
      strictPort: true,
      // Vite rejects requests whose Host header it does not recognise. A phone
      // arrives as an IP, and a tunnel as a random subdomain, so both would be
      // refused. Dev server only — this has no effect on the built SPA.
      allowedHosts: true,
      proxy: {
        '/api': {
          target: backendUrl,
          changeOrigin: true,
        },
        '/sanctum': {
          target: backendUrl,
          changeOrigin: true,
        },
        // The SPA posts credentials to '/auth/login' and '/auth/logout', which
        // are rewritten back to the API's real '/login' and '/logout' here.
        //
        // The indirection exists because '/login' is ALSO the SPA's own page
        // route. Proxying that path meant a hard navigation or refresh on the
        // login page (a GET) hit Laravel, which only defines POST there, so this
        // block used to carry a `bypass` returning index.html for GETs. The
        // deployed Vercel proxy has no equivalent escape hatch — its rewrites
        // cannot match on method — so the paths were separated instead, which
        // retires the hack here too.
        '/auth/login': {
          target: backendUrl,
          changeOrigin: true,
          rewrite: () => '/login',
        },
        '/auth/logout': {
          target: backendUrl,
          changeOrigin: true,
          rewrite: () => '/logout',
        },
        // Google OAuth entry points live on the API as web routes (they are
        // top-level browser navigations, not XHR). Proxying them keeps dev
        // same-origin. In the deployed setup web/vercel.json proxies them the
        // same way, and GOOGLE_REDIRECT_URI points at the SPA origin so the
        // callback's session cookie lands on the host the SPA actually reads.
        '/auth/google': {
          target: backendUrl,
          changeOrigin: true,
        },
        // No '/register' entry: self-service registration was removed from the
        // API (see routes/auth.php), so proxying it would only forward to a 404.
        //
        // Password reset uses the SAME '/auth/*' indirection as login, and for
        // the same reason: '/forgot-password' is now the SPA's own page route,
        // so proxying that path wholesale would send a page load (a GET) to
        // Laravel, which only defines POST there. The SPA owns
        // '/forgot-password' and '/password-reset/:token' as pages; these two
        // proxy-only paths carry the actual credentials POSTs.
        '/auth/forgot-password': {
          target: backendUrl,
          changeOrigin: true,
          rewrite: () => '/forgot-password',
        },
        '/auth/reset-password': {
          target: backendUrl,
          changeOrigin: true,
          rewrite: () => '/reset-password',
        },
      },
    },
  }
})

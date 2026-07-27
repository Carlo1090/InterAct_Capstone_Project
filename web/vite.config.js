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
      port: 5173,
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
        '/forgot-password': {
          target: backendUrl,
          changeOrigin: true,
        },
        '/reset-password': {
          target: backendUrl,
          changeOrigin: true,
        },
      },
    },
  }
})

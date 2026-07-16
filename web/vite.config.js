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
        '/login': {
          target: backendUrl,
          changeOrigin: true,
          // '/login' is both the Sanctum POST endpoint AND the Vue Router
          // page route. A hard navigation/refresh on the login page (GET)
          // must fall through to the SPA (index.html) instead of hitting
          // the backend, which only defines a POST route there and would
          // 405 — only the actual POST submit should be proxied.
          bypass: (req) => {
            if (req.method === 'GET') return '/index.html'
          },
        },
        '/logout': {
          target: backendUrl,
          changeOrigin: true,
        },
        '/register': {
          target: backendUrl,
          changeOrigin: true,
        },
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

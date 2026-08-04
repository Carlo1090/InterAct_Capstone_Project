import { defineStore } from 'pinia'
import api from '@/lib/axios'

type Department = {
  id: number
  name: string
}

type Program = {
  id: number
  name: string
  department: Department
}

export type AuthUser = {
  id: number
  name: string
  username: string
  email: string | null
  // Set ONLY by the Google verification flow. Non-null means Google confirmed
  // the address, which is what unlocks reminder email and Google sign-in.
  email_verified_at: string | null
  role: string
  must_change_password: boolean
  avatar_url: string | null
  program?: Program | null
  // Students only: true until their info sheet is approved (enrollment gate).
  student_gated?: boolean
  // Students only: true when they cleared intake but were dropped from their
  // batch (no active/completed enrollment) — the "enrollment inactive" state.
  student_paused?: boolean
}

type AuthState = {
  user: AuthUser | null
}

// Memoised at module scope so priming the cookie on the login page's mount and
// awaiting it on submit share ONE request. A user who submits instantly just
// waits on the in-flight call rather than racing a second one against it.
let csrfPromise: Promise<unknown> | null = null

/**
 * Fetch Sanctum's XSRF cookie, at most once per page load.
 *
 * Called fire-and-forget when the login form mounts so this round trip happens
 * while the user is still typing, instead of blocking the moment they hit
 * Login. login() awaits the same promise, so correctness does not depend on the
 * prime having succeeded — or having been called at all.
 */
export function ensureCsrfCookie(): Promise<unknown> {
  csrfPromise ??= api.get('/sanctum/csrf-cookie').catch((error) => {
    // Let the next caller retry rather than caching the failure forever.
    csrfPromise = null
    throw error
  })

  return csrfPromise
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    user: null,
  }),
  getters: {
    isLoggedIn: (state): boolean => state.user !== null,
    role: (state): string | null => state.user?.role ?? null,
  },
  actions: {
    async login(identifier: string, password: string): Promise<void> {
      try {
        // Normally already resolved — LoginPage primes this on mount.
        await ensureCsrfCookie()
        // NOT '/login'. That path is ALSO this SPA's own router page route, and
        // the deployed proxy (web/vercel.json) cannot tell a page GET from this
        // credential POST — Vercel rewrites match on path, never on method — so
        // proxying '/login' wholesale would make the login page itself proxy to
        // Laravel, which only defines POST there, and answer 405.
        //
        // '/auth/login' is a proxy-only path with no page behind it. Both the
        // Vercel rewrite and the Vite dev proxy map it back to the API's real
        // '/login', so no backend route changed.
        const response = await api.post<{ user: AuthUser }>('/auth/login', {
          login: identifier,
          password,
        })

        // Deliberately NOT followed by fetchUser(). The login response is built
        // by the same App\Support\AuthUserPayload as GET /api/user, so it is
        // already the complete user — re-fetching it cost a third blocking
        // round trip and returned identical data.
        this.user = response.data.user
      } catch (error) {
        this.user = null
        throw error
      }
    },
    async fetchUser(): Promise<void> {
      try {
        const response = await api.get<AuthUser>('/api/user')
        this.user = response.data
      } catch (error) {
        this.user = null
        throw error
      }
    },
    async logout(): Promise<void> {
      try {
        // '/auth/logout' for the same reason as '/auth/login' above.
        await api.post('/auth/logout')
      } catch (error) {
        throw error
      } finally {
        this.user = null
      }
    },
  },
})

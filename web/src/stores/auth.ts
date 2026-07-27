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
        await api.get('/sanctum/csrf-cookie')
        // NOT '/login'. That path is ALSO this SPA's own router page route, and
        // the deployed proxy (web/vercel.json) cannot tell a page GET from this
        // credential POST — Vercel rewrites match on path, never on method — so
        // proxying '/login' wholesale would make the login page itself proxy to
        // Laravel, which only defines POST there, and answer 405.
        //
        // '/auth/login' is a proxy-only path with no page behind it. Both the
        // Vercel rewrite and the Vite dev proxy map it back to the API's real
        // '/login', so no backend route changed.
        await api.post('/auth/login', { login: identifier, password })
        await this.fetchUser()
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

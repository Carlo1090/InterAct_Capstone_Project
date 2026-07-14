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
  role: string
  must_change_password: boolean
  program?: Program | null
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
        await api.post('/login', { login: identifier, password })
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
        await api.post('/logout')
      } catch (error) {
        throw error
      } finally {
        this.user = null
      }
    },
  },
})

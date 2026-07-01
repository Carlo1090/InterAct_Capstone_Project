import 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    role?: 'admin' | 'coordinator' | 'supervisor' | 'student'
    title?: string
  }
}

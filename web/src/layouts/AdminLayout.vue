<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const navItems = [
  { label: 'Dashboard', to: '/admin/dashboard', badge: '' },
  { label: 'Users', to: '/admin/users', badge: '' },
  { label: 'Departments', to: '/admin/departments', badge: '' },
  { label: 'Batches', to: '/admin/batches', badge: '' },
  { label: 'Companies', to: '/admin/companies', badge: '' },
  { label: 'Student Info Sheet', to: '/admin/info-sheets', badge: '' },
  { label: 'Annual SIPP Report', to: '/admin/annual-sipp', badge: '' },
  { label: 'Audit Logs', to: '/admin/audit-logs', badge: '' },
  { label: 'System Settings', to: '/admin/settings', badge: '' },
]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const pageTitle = computed(() => (typeof route.meta.title === 'string' ? route.meta.title : 'Dashboard'))
const userName = computed(() => auth.user?.name ?? 'Test Admin')
const initials = computed(() =>
  userName.value
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

const logout = async () => {
  try {
    await auth.logout()
  } finally {
    router.push('/login')
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-800">
    <aside class="fixed inset-y-0 left-0 z-20 flex w-64 flex-col border-r border-blue-600 bg-blue-500">
      <div class="flex items-center gap-3 border-b border-blue-400 px-5 py-5">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-sm font-bold text-blue-600">IT</div>
        <div>
          <p class="text-sm font-bold text-white">InternTrack</p>
          <p class="text-xs text-blue-100">Journal & Monitoring</p>
        </div>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium text-blue-50 transition hover:bg-blue-600 hover:text-white"
          active-class="bg-white text-blue-600"
        >
          <span>{{ item.label }}</span>
          <span v-if="item.badge" class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">{{ item.badge }}</span>
        </RouterLink>
      </nav>

      <div class="border-t border-blue-400 p-3">
        <div class="mb-3 flex items-center gap-3 px-3 py-2">
          <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold text-blue-600">
            {{ initials }}
          </div>
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-white">{{ userName }}</p>
            <p class="truncate text-xs text-blue-100">System Administrator</p>
          </div>
        </div>
        <button
          type="button"
          class="w-full rounded-md px-3 py-2 text-left text-sm font-medium text-blue-100 transition hover:bg-white hover:text-red-700"
          @click="logout"
        >
          Log Out
        </button>
      </div>
    </aside>

    <div class="ml-64 min-h-screen">
      <header class="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-8">
        <h1 class="text-lg font-bold text-slate-950">{{ pageTitle }}</h1>
      </header>

      <main class="p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>

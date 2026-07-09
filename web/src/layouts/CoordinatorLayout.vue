<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const navItems = [
  { label: 'Department Dashboard', to: '/coordinator/dashboard', badge: '' },
  { label: 'Interns', to: '/coordinator/interns', badge: '' },
  { label: 'Daily Journal Activities', to: '/coordinator/journal-activities', badge: '4' },
  { label: 'Journal Templates', to: '/coordinator/journal-templates', badge: '' },
  { label: 'Batches', to: '/coordinator/batches', badge: '' },
  { label: 'Partner Companies', to: '/coordinator/companies', badge: '' },
  { label: 'Student Info Sheets', to: '/coordinator/info-sheets', badge: '' },
  { label: 'Annual SIPP Report', to: '/coordinator/annual-sipp', badge: '' },
  { label: 'HTE & Student Interns List', to: '/coordinator/hte', badge: '' },
]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const pageTitle = computed(() => (typeof route.meta.title === 'string' ? route.meta.title : 'Coordinator Dashboard'))
const userName = computed(() => auth.user?.name ?? 'Prof. Alicia Montoya')
const initials = computed(() =>
  userName.value
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)
const department = computed(() => auth.user?.program?.department?.name ?? 'Business Administration')

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
    <aside class="fixed inset-y-0 left-0 z-20 flex w-64 flex-col border-r border-slate-200 bg-white">
      <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-5">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-700 text-sm font-bold text-white">IT</div>
        <div>
          <p class="text-sm font-bold text-slate-950">InternTrack</p>
          <p class="text-xs text-slate-500">Journal & Monitoring</p>
        </div>
      </div>

      <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-700 text-sm font-bold text-white">
          {{ initials }}
        </div>
        <div class="min-w-0">
          <p class="truncate text-sm font-semibold text-slate-950">{{ userName }}</p>
          <p class="truncate text-xs text-slate-500">Coordinator - {{ department }}</p>
        </div>
      </div>

      <nav class="flex-1 space-y-1 px-3 py-4">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-blue-700"
          active-class="bg-blue-50 text-blue-700"
        >
          <span>{{ item.label }}</span>
          <span v-if="item.badge" class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">{{ item.badge }}</span>
        </RouterLink>
      </nav>

      <div class="border-t border-slate-100 p-3">
        <button
          type="button"
          class="w-full rounded-md px-3 py-2 text-left text-sm font-medium text-slate-500 transition hover:bg-red-50 hover:text-red-700"
          @click="logout"
        >
          Log Out
        </button>
      </div>
    </aside>

    <div class="ml-64 min-h-screen">
      <header class="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-8">
        <h1 class="text-lg font-bold text-slate-950">{{ pageTitle }}</h1>
        <RouterLink
          to="/coordinator/interns"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
        >
          View Interns
        </RouterLink>
      </header>

      <main class="p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>

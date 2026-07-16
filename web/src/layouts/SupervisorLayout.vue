<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const navItems = [
  { label: 'Dashboard', to: '/supervisor/dashboard', badge: '', icon: 'dashboard' },
  { label: 'Journals', to: '/supervisor/journals', badge: '5', icon: 'journals' },
  { label: 'Interns', to: '/supervisor/interns', badge: '', icon: 'people' },
]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const collapsed = ref(false)

const pageTitle = computed(() => (typeof route.meta.title === 'string' ? route.meta.title : 'Supervisor Dashboard'))
const userName = computed(() => auth.user?.name ?? 'Engr. Ramon Villanueva')
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
    <div class="fixed inset-x-0 top-0 z-30 h-1.5 bg-slate-900" />

    <aside
      class="fixed inset-y-0 left-0 z-20 flex flex-col overflow-visible bg-linear-to-b from-blue-600 to-indigo-700 text-white transition-all duration-200"
      :class="collapsed ? 'w-20' : 'w-64'"
    >
      <div class="flex items-center gap-3 px-5 py-5" :class="collapsed && 'justify-center px-0'">
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white shadow">
          <img src="/images/mdc-logo.png" alt="Mater Dei College seal" class="h-9 w-9 rounded-full object-contain" />
        </div>
        <p v-if="!collapsed" class="truncate text-base font-bold">InternTrack</p>
      </div>

      <div class="mx-3 mb-2 border-t border-white/20" />

      <nav class="flex-1 space-y-1 px-3 py-2">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium text-blue-100 transition hover:bg-white/10 hover:text-white"
          :class="collapsed && 'justify-center px-0'"
          active-class="bg-white/15 text-white"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 shrink-0">
            <rect v-if="item.icon === 'dashboard'" x="3.5" y="3.5" width="7" height="7" rx="1.5" fill="currentColor" />
            <rect v-if="item.icon === 'dashboard'" x="13.5" y="3.5" width="7" height="7" rx="1.5" fill="currentColor" />
            <rect v-if="item.icon === 'dashboard'" x="3.5" y="13.5" width="7" height="7" rx="1.5" fill="currentColor" />
            <rect v-if="item.icon === 'dashboard'" x="13.5" y="13.5" width="7" height="7" rx="1.5" fill="currentColor" />

            <rect v-if="item.icon === 'journals'" x="4.5" y="3.5" width="15" height="17" rx="1.5" stroke="currentColor" stroke-width="1.6" />
            <path v-if="item.icon === 'journals'" d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />

            <circle v-if="item.icon === 'people'" cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.6" />
            <path v-if="item.icon === 'people'" d="M3.5 19c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            <circle v-if="item.icon === 'people'" cx="16.5" cy="8.5" r="2.3" stroke="currentColor" stroke-width="1.4" />
            <path v-if="item.icon === 'people'" d="M15.2 14.7c2.3.3 3.9 1.7 4.4 4.3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />

            <circle v-if="item.icon === 'profile'" cx="12" cy="8.5" r="3.5" stroke="currentColor" stroke-width="1.6" />
            <path v-if="item.icon === 'profile'" d="M4.5 19.5c1-3.6 3.8-5.5 7.5-5.5s6.5 1.9 7.5 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
          </svg>
          <span v-if="!collapsed" class="min-w-0 flex-1 truncate">{{ item.label }}</span>
          <span
            v-if="item.badge && !collapsed"
            class="rounded-full bg-red-500 px-2 py-0.5 text-xs font-bold text-white"
          >{{ item.badge }}</span>
        </RouterLink>
      </nav>

      <div class="border-t border-white/20 p-3">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-medium text-blue-100 transition hover:bg-white/10 hover:text-white"
          :class="collapsed && 'justify-center px-0'"
          @click="logout"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 shrink-0">
            <path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M16 16l4-4-4-4M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <span v-if="!collapsed">Log Out</span>
        </button>
      </div>

      <button
        type="button"
        class="absolute -right-3.5 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full bg-indigo-700 text-white shadow-md ring-2 ring-white transition hover:bg-indigo-800"
        :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        @click="collapsed = !collapsed"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 transition-transform" :class="collapsed && 'rotate-180'">
          <path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
    </aside>

    <div class="min-h-screen transition-all duration-200" :class="collapsed ? 'ml-20' : 'ml-64'">
      <header class="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-8">
        <div class="flex items-center gap-4">
          <h1 class="text-lg font-bold text-slate-950">{{ pageTitle }}</h1>
          <RouterLink
            v-if="route.path !== '/supervisor/journals'"
            to="/supervisor/journals"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
          >
            Review Journals
          </RouterLink>
        </div>

        <div class="flex items-center gap-3">
          <div class="text-right leading-tight">
            <p class="text-sm font-bold uppercase tracking-wide text-slate-700">{{ userName }}</p>
            <p class="text-xs text-slate-400">Company Supervisor - TechPH Inc.</p>
          </div>
          <RouterLink
            to="/supervisor/profile"
            title="Profile"
            class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-sm font-bold text-white ring-offset-2 transition hover:ring-2 hover:ring-blue-600"
          >
            <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="Profile photo" class="h-full w-full object-cover" />
            <span v-else>{{ initials }}</span>
          </RouterLink>
        </div>
      </header>

      <main class="p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>

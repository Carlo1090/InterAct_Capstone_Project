<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const navItems = [{ label: 'Dashboard', to: '/supervisor/dashboard' }]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const pageTitle = computed(() => (typeof route.meta.title === 'string' ? route.meta.title : 'Dashboard'))
const userName = computed(() => auth.user?.name ?? 'User')

const logout = async () => {
  try {
    await auth.logout()
  } finally {
    router.push('/login')
  }
}
</script>

<template>
  <div class="flex min-h-screen bg-slate-100">
    <aside class="fixed inset-y-0 left-0 flex w-64 flex-col bg-slate-950 text-slate-200">
      <div class="border-b border-slate-800 px-6 py-5">
        <p class="text-xl font-semibold text-white">InternTrack</p>
        <p class="mt-1 text-xs text-slate-400">Progress Monitoring</p>
      </div>

      <nav class="flex-1 space-y-1 px-3 py-4">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="block rounded-md px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-slate-800 hover:text-white"
          active-class="bg-slate-800 text-white"
        >
          {{ item.label }}
        </RouterLink>
      </nav>

      <div class="border-t border-slate-800 p-4">
        <button
          type="button"
          class="w-full rounded-md bg-white px-3 py-2 text-sm font-semibold text-slate-950 transition hover:bg-slate-200"
          @click="logout"
        >
          Logout
        </button>
      </div>
    </aside>

    <div class="ml-64 flex min-h-screen flex-1 flex-col">
      <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6">
        <h1 class="text-lg font-semibold text-slate-900">{{ pageTitle }}</h1>
        <p class="text-sm text-slate-500">Signed in as {{ userName }}</p>
      </header>

      <main class="flex-1 p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>

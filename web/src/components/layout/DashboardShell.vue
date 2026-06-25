<script setup>
defineProps({
  roleLabel: { type: String, required: true },
  navItems: { type: Array, required: true },
})
</script>

<template>
  <div class="flex min-h-screen bg-slate-50">
    <!-- Sidebar -->
    <aside class="w-64 bg-slate-900 text-slate-200 flex flex-col">
      <div class="px-6 py-5 text-xl font-semibold text-white border-b border-slate-700">
        InternTrack
      </div>
      <nav class="flex-1 px-3 py-4 space-y-1">
        <a
          v-for="item in navItems"
          :key="item"
          href="#"
          class="block rounded-md px-3 py-2 text-sm hover:bg-slate-700 transition-colors"
        >
          {{ item }}
        </a>
      </nav>
      <div class="px-4 py-4 border-t border-slate-700 text-xs text-slate-400">
        Phase 1 static shell — no live data yet
      </div>
    </aside>

    <!-- Main area -->
    <div class="flex-1 flex flex-col">
      <!-- Topbar -->
      <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-6">
        <h1 class="text-lg font-medium text-slate-800">{{ roleLabel }} Dashboard</h1>
        <div class="text-sm text-slate-500">Signed in as — (placeholder)</div>
      </header>

      <!-- Content -->
      <main class="flex-1 p-6">
        <slot />
      </main>
    </div>
  </div>
</template>

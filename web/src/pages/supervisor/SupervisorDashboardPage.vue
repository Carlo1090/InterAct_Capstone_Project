<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { SupervisorDashboard, SupervisorReviewStatus } from '@/types/api'

const dashboard = ref<SupervisorDashboard | null>(null)
const isLoading = ref(true)
const errorMessage = ref('')

const statCards = computed(() => {
  const s = dashboard.value?.stats
  return [
    { label: 'My Interns', value: s?.my_interns ?? 0, sub: 'Assigned to you', tone: 'blue' },
    { label: 'Pending Reviews', value: s?.pending_reviews ?? 0, sub: 'Weekly journals to review', tone: 'amber' },
    { label: 'Approved', value: s?.approved_total ?? 0, sub: 'Weekly journals', tone: 'green' },
    { label: 'Returned', value: s?.returned_total ?? 0, sub: 'Sent back for revision', tone: 'red' },
  ]
})

const statToneClass = (tone: string): string => {
  const classes: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-700',
    green: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  }
  return classes[tone] ?? classes.blue
}

const statusClass = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'bg-green-50 text-green-700'
  if (status === 'returned') return 'bg-red-50 text-red-700'
  return 'bg-amber-50 text-amber-700'
}

const formatDateTime = (iso: string | null): string => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.get<SupervisorDashboard>('/api/supervisor/dashboard')
    dashboard.value = data
  } catch {
    errorMessage.value = 'Unable to load your dashboard.'
  } finally {
    isLoading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      You review the <strong>weekly narrative journals</strong> of the interns assigned to you. Approve them, or return
      them with a comment explaining what to fix.
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <template v-else>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article v-for="card in statCards" :key="card.label" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div class="mb-4 flex h-9 w-9 items-center justify-center rounded-md text-sm font-bold" :class="statToneClass(card.tone)">
            {{ card.value }}
          </div>
          <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ card.label }}</p>
          <p class="mt-1 text-3xl font-bold text-slate-950">{{ card.value }}</p>
          <p class="mt-1 text-xs text-slate-500">{{ card.sub }}</p>
        </article>
      </div>

      <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-bold text-slate-900">Recently Reviewed</h2>
          <RouterLink to="/supervisor/journals" class="text-xs font-semibold text-blue-700 hover:text-blue-800">Review journals →</RouterLink>
        </div>

        <p v-if="(dashboard?.recently_reviewed.length ?? 0) === 0" class="mt-4 text-sm text-slate-400">
          You haven't reviewed any weekly journals yet.
        </p>

        <div v-else class="mt-4 divide-y divide-slate-100">
          <div v-for="log in dashboard?.recently_reviewed ?? []" :key="log.id" class="flex items-center justify-between gap-4 py-3">
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ log.student_name }}</p>
              <p class="mt-1 text-xs text-slate-400">Week of {{ log.week_start }} · reviewed {{ formatDateTime(log.reviewed_at) }}</p>
            </div>
            <span class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold capitalize" :class="statusClass(log.status)">{{ log.status }}</span>
          </div>
        </div>
      </section>
    </template>
  </section>
</template>

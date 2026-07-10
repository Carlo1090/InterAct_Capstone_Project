<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import { useAuthStore } from '@/stores/auth'
import type { CoordinatorDashboard, CoordinatorDashboardStats, StudentBehind } from '@/types/api'

const auth = useAuthStore()

const stats = ref<CoordinatorDashboardStats>({
  active_interns: 0,
  journals_submitted_this_week: 0,
  journals_missing_this_week: 0,
  active_batches: 0,
  students_behind: 0,
})
const studentsBehind = ref<StudentBehind[]>([])
const week = ref<{ start: string; end: string }>({ start: '', end: '' })

const isLoading = ref(true)
const errorMessage = ref('')

const department = computed(() => auth.user?.program?.department?.name ?? 'your department')

const statCards = computed(() => [
  { label: 'My Interns', value: stats.value.active_interns, sub: 'Active enrollments in scope', tone: 'blue' },
  { label: 'Submitted This Week', value: stats.value.journals_submitted_this_week, sub: `Journals since ${week.value.start}`, tone: 'green' },
  { label: 'Missing This Week', value: stats.value.journals_missing_this_week, sub: 'Unsubmitted daily journals', tone: 'red' },
  { label: 'Active Batches', value: stats.value.active_batches, sub: 'Running in your programs', tone: 'amber' },
])

const statToneClass = (tone: string): string => {
  const classes: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-700',
    green: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  }

  return classes[tone] ?? classes.blue
}

const loadDashboard = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<CoordinatorDashboard>('/api/coordinator/dashboard')
    stats.value = data.stats
    studentsBehind.value = data.students_behind
    week.value = data.week
  } catch {
    errorMessage.value = 'Unable to load your dashboard.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadDashboard)
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This workspace is scoped to <strong>{{ department }}</strong>. Stats below reflect your assigned programs only.
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
          <h2 class="text-sm font-bold text-slate-900">Students Behind This Week</h2>
          <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700">{{ stats.students_behind }} flagged</span>
        </div>

        <p v-if="studentsBehind.length === 0" class="mt-4 text-sm text-slate-400">
          No students have missing daily journals this week. 🎉
        </p>

        <div v-else class="mt-4 divide-y divide-slate-100">
          <div v-for="student in studentsBehind" :key="student.student_id" class="flex items-center justify-between gap-4 py-3">
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ student.name }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ student.company || 'No company on file' }}</p>
            </div>
            <span class="whitespace-nowrap rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700">
              {{ student.missing_count }} missing {{ student.missing_count === 1 ? 'entry' : 'entries' }}
            </span>
          </div>
        </div>
      </section>
    </template>
  </section>
</template>

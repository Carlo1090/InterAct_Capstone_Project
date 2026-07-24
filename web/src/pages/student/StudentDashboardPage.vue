<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import { categorizeError } from '@/lib/apiError'
import { isNotEnrolledError } from '@/lib/enrollment'
import type { StudentDashboard } from '@/types/api'

const dashboard = ref<StudentDashboard | null>(null)
const isLoading = ref(true)
const errorMessage = ref('')
const notEnrolled = ref(false)

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  notEnrolled.value = false

  try {
    const { data } = await api.get<StudentDashboard>('/api/student/dashboard')
    dashboard.value = data
  } catch (error) {
    if (isNotEnrolledError(error)) {
      notEnrolled.value = true
    } else {
      errorMessage.value = categorizeError(error, 'Unable to load your dashboard.').message
    }
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  if (!dashboard.value) return []
  const s = dashboard.value.stats

  return [
    { label: 'Entries Submitted', value: String(s.entries_submitted_total), sub: 'All time', tone: 'blue' },
    { label: 'Weekly Reports Approved', value: String(s.weekly_logs_approved), sub: 'By supervisor', tone: 'green' },
    { label: 'Pending Review', value: String(s.weekly_logs_pending), sub: 'Awaiting supervisor', tone: 'amber' },
    { label: 'Missing Entries', value: String(s.missing_this_week), sub: 'This week', tone: 'red' },
  ]
})

const progress = computed(() => {
  if (!dashboard.value) return []
  const p = dashboard.value.progress

  return [
    { label: 'Weekly Reports Approved', value: p.weekly_reports_approved_percent, barClass: 'bg-green-600', textClass: 'text-green-700' },
    { label: 'OJT Duration Progress', value: p.ojt_duration_percent, barClass: 'bg-amber-600', textClass: 'text-amber-700' },
  ]
})

const details = computed(() => {
  if (!dashboard.value) return []
  const i = dashboard.value.internship

  return [
    ['Host Company', i.host_company ?? '—'],
    ['Supervisor', i.supervisor ?? '—'],
    ['Coordinator', i.coordinator ?? '—'],
    ['Department', i.department ?? '—'],
    ['Start Date', i.start_date ?? '—'],
  ]
})

const statAccentClass = (tone: string): string => {
  const classes: Record<string, string> = {
    blue: 'bg-blue-600',
    green: 'bg-green-600',
    amber: 'bg-amber-500',
    red: 'bg-red-500',
    slate: 'bg-slate-400',
  }

  return classes[tone] ?? classes.slate
}

const activityDotClass = (tone: string): string => statAccentClass(tone)

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <NotEnrolledNotice v-if="notEnrolled" />

      <template v-else-if="dashboard">
        <div
          v-if="dashboard.stats.missing_this_week > 0"
          class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
          You have <strong>{{ dashboard.stats.missing_this_week }} missing entr{{ dashboard.stats.missing_this_week === 1 ? 'y' : 'ies' }}</strong>
          this week ({{ dashboard.week.start }} to {{ dashboard.week.end }}).
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <article
            v-for="stat in stats"
            :key="stat.label"
            class="overflow-hidden rounded-lg bg-white text-center shadow-sm ring-1 ring-slate-200"
          >
            <div class="h-1" :class="statAccentClass(stat.tone)" />
            <div class="px-5 py-6">
              <p class="text-4xl font-extrabold text-slate-900">{{ stat.value }}</p>
              <div class="mx-auto my-3 h-px w-10 bg-slate-200" />
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ stat.label }}</p>
              <p class="mt-1 text-xs text-slate-400">{{ stat.sub }}</p>
            </div>
          </article>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
          <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="border-l-4 border-blue-600 pl-3 text-sm font-bold text-slate-900">Completion Progress</h2>
            <div class="mt-5 space-y-4">
              <div v-for="item in progress" :key="item.label">
                <div class="mb-2 flex justify-between text-sm">
                  <span class="text-slate-600">{{ item.label }}</span>
                  <span class="font-bold" :class="item.textClass">{{ item.value }}%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                  <div class="h-full rounded-full" :class="item.barClass" :style="{ width: `${item.value}%` }"></div>
                </div>
              </div>
            </div>
          </section>

          <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="border-l-4 border-blue-600 pl-3 text-sm font-bold text-slate-900">Recent Activity</h2>
            <div v-if="dashboard.recent_activity.length === 0" class="mt-4 text-sm text-slate-400">No recent activity yet.</div>
            <div v-else class="mt-4 divide-y divide-slate-100">
              <div v-for="(activity, index) in dashboard.recent_activity" :key="index" class="flex gap-3 py-3">
                <span class="mt-1.5 h-2 w-2 rounded-full" :class="activityDotClass(activity.tone)"></span>
                <div>
                  <p class="text-sm text-slate-800">{{ activity.text }}</p>
                  <p class="mt-1 text-xs text-slate-400">{{ activity.time }}</p>
                </div>
              </div>
            </div>
          </section>
        </div>

        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between">
            <h2 class="border-l-4 border-blue-600 pl-3 text-sm font-bold text-slate-900">Internship Details</h2>
            <span v-if="dashboard.internship.program" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
              {{ dashboard.internship.program }}
            </span>
          </div>
          <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="[label, value] in details" :key="label" class="border-b border-slate-100 pb-3">
              <p class="text-xs text-slate-400">{{ label }}</p>
              <p class="mt-1 text-sm font-semibold text-slate-900">{{ value }}</p>
            </div>
          </div>
        </section>
      </template>
    </LoadStatus>
  </section>
</template>

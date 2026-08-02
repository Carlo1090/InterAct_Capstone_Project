<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import StatCardGridSkeleton from '@/components/ui/skeletons/StatCardGridSkeleton.vue'
import SectionCardSkeleton from '@/components/ui/skeletons/SectionCardSkeleton.vue'
import { categorizeError } from '@/lib/apiError'
import { isNotEnrolledError } from '@/lib/enrollment'
import { useAuthStore } from '@/stores/auth'
import { consumeQueryParam, googleErrorMessage, googleVerifyUrl } from '@/lib/googleAuth'
import type { StudentDashboard } from '@/types/api'

const auth = useAuthStore()

// Reminder email only goes to a Google-verified address, so an unverified
// student silently gets in-app notifications only. Say so rather than letting
// them wonder why no email ever arrives.
const needsEmailVerification = computed(() => !auth.user?.email_verified_at)
const verifyWithGoogle = () => {
  window.location.href = googleVerifyUrl()
}

// One-shot status the OAuth callback redirects back with.
const verifiedJustNow = ref(false)
const emailErrorMessage = ref('')

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

onMounted(async () => {
  verifiedJustNow.value = consumeQueryParam('email_verified') === '1'
  emailErrorMessage.value = googleErrorMessage(consumeQueryParam('email_error'))

  // The callback wrote email_verified_at server-side; refresh the cached user
  // so the banner disappears without a manual reload.
  if (verifiedJustNow.value) {
    await auth.fetchUser()
  }

  await load()
})
</script>

<template>
  <section class="space-y-5">
    <div
      v-if="verifiedJustNow"
      class="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="mt-0.5 h-5 w-5 shrink-0 text-green-600">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" />
        <path d="M8 12.5l2.5 2.5L16 9.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      <p class="text-sm text-green-800">
        Email verified. You will now get journal reminders by email, and you can sign in with Google.
      </p>
    </div>

    <p v-if="emailErrorMessage" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ emailErrorMessage }}
    </p>

    <div
      v-else-if="needsEmailVerification"
      class="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
    >
      <div class="flex items-start gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600">
          <path d="M12 8.5v4.5M12 16.5h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          <path d="M10.3 3.9 2.6 17.4A2 2 0 0 0 4.3 20.4h15.4a2 2 0 0 0 1.7-3l-7.7-13.5a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
        </svg>
        <div>
          <p class="text-sm font-semibold text-amber-900">Your email is not verified</p>
          <p class="text-xs text-amber-800">
            Journal reminders are only emailed to a verified address — right now you get in-app
            notifications only. Verifying also lets you sign in with Google.
          </p>
        </div>
      </div>
      <button
        type="button"
        class="flex shrink-0 items-center justify-center gap-2 rounded-md border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
        @click="verifyWithGoogle"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 18 18" class="h-4 w-4">
          <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z" />
          <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z" />
          <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z" />
          <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z" />
        </svg>
        Verify with Google
      </button>
    </div>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <template #skeleton>
        <StatCardGridSkeleton :count="4" />
        <div class="mt-5 grid gap-5 xl:grid-cols-2">
          <SectionCardSkeleton variant="progress" :rows="2" />
          <SectionCardSkeleton variant="activity" :rows="3" />
        </div>
        <div class="mt-5">
          <SectionCardSkeleton variant="details" :rows="5" />
        </div>
      </template>

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

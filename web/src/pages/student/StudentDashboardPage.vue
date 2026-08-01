<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import { categorizeError } from '@/lib/apiError'
import { isNotEnrolledError } from '@/lib/enrollment'
import { useAuthStore } from '@/stores/auth'
import { consumeQueryParam, googleErrorMessage, googleVerifyUrl } from '@/lib/googleAuth'
import type { StudentDashboard } from '@/types/api'

const auth = useAuthStore()

// The sidebar's own "Write Daily Journal" target — kept identical to the nav
// item in StudentLayout.vue rather than re-derived here.
const WRITE_JOURNAL_ROUTE = '/student/write-journal'

// 2πr for the progress rings' r=52 circle.
const RING_CIRCUMFERENCE = 326.73

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

// Same derivation the header avatar uses in ProfileMenuPopover.vue, so the two
// initials never disagree.
const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

const firstName = computed(() => (auth.user?.name ?? '').trim().split(/\s+/)[0] ?? '')
const greeting = computed(() => (firstName.value ? `Hello, ${firstName.value}` : 'Hello'))

const heroSubline = computed(() => {
  const i = dashboard.value?.internship
  if (!i) return ''

  return `${i.host_company ?? '—'} · ${i.supervisor ?? '—'}`
})

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
    { label: 'Weekly Reports Approved', value: p.weekly_reports_approved_percent, ringClass: 'text-green-600' },
    { label: 'OJT Duration Progress', value: p.ojt_duration_percent, ringClass: 'text-amber-500' },
  ]
})

// Host Company and Supervisor also head the hero card, so the meta grid carries
// the remaining internship fields plus Host Company for at-a-glance scanning.
const details = computed<[string, string][]>(() => {
  if (!dashboard.value) return []
  const i = dashboard.value.internship

  return [
    ['Coordinator', i.coordinator ?? '—'],
    ['Department', i.department ?? '—'],
    ['Start Date', i.start_date ?? '—'],
    ['Host Company', i.host_company ?? '—'],
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

// A ring can only ever draw between empty and full, whatever the API reports.
const ringOffset = (value: number): number => {
  const clamped = Math.min(100, Math.max(0, value))

  return RING_CIRCUMFERENCE * (1 - clamped / 100)
}

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
  <section class="space-y-6">
    <div
      v-if="verifiedJustNow"
      class="flex items-start gap-3 rounded-xl bg-green-50 px-5 py-4 ring-1 ring-green-100"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="mt-0.5 h-5 w-5 shrink-0 text-green-600">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" />
        <path d="M8 12.5l2.5 2.5L16 9.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      <p class="text-sm text-green-800">
        Email verified. You will now get journal reminders by email, and you can sign in with Google.
      </p>
    </div>

    <p v-if="emailErrorMessage" class="rounded-xl bg-red-50 px-5 py-4 text-sm text-red-700 ring-1 ring-red-100">
      {{ emailErrorMessage }}
    </p>

    <div
      v-else-if="needsEmailVerification"
      class="flex flex-col gap-3 rounded-xl bg-amber-50 px-5 py-4 ring-1 ring-amber-100 sm:flex-row sm:items-center sm:justify-between"
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
        class="flex shrink-0 items-center justify-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-amber-200 transition hover:bg-slate-50"
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
      <NotEnrolledNotice v-if="notEnrolled" />

      <template v-else-if="dashboard">
        <div
          v-if="dashboard.stats.missing_this_week > 0"
          class="rounded-xl bg-amber-50 px-5 py-4 text-sm text-amber-800 ring-1 ring-amber-100"
        >
          You have <strong>{{ dashboard.stats.missing_this_week }} missing entr{{ dashboard.stats.missing_this_week === 1 ? 'y' : 'ies' }}</strong>
          this week ({{ dashboard.week.start }} to {{ dashboard.week.end }}).
        </div>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-4">
              <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-blue-50 text-lg font-semibold text-blue-700">
                {{ initials }}
              </span>
              <div class="min-w-0">
                <p class="text-xl font-semibold tracking-tight text-slate-900">{{ greeting }}</p>
                <p class="mt-0.5 truncate text-sm text-slate-500">{{ heroSubline }}</p>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
              <span
                v-if="dashboard.internship.program"
                class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"
              >
                {{ dashboard.internship.program }}
              </span>
              <RouterLink
                :to="WRITE_JOURNAL_ROUTE"
                class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
              >
                Write Daily Journal
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                  <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </RouterLink>
            </div>
          </div>

          <div class="mt-6 grid gap-4 border-t border-slate-100 pt-6 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="[label, value] in details" :key="label">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ label }}</p>
              <p class="mt-1 text-sm font-semibold text-slate-900">{{ value }}</p>
            </div>
          </div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <article
            v-for="stat in stats"
            :key="stat.label"
            class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70"
          >
            <div class="flex items-center gap-2">
              <span class="h-2 w-2 shrink-0 rounded-full" :class="statAccentClass(stat.tone)" />
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ stat.label }}</p>
            </div>
            <p class="mt-3 text-3xl font-semibold tracking-tight text-slate-900">{{ stat.value }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ stat.sub }}</p>
          </article>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
          <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
            <h2 class="text-sm font-semibold text-slate-900">Completion Progress</h2>
            <div class="mt-6 grid grid-cols-2 gap-6">
              <div v-for="item in progress" :key="item.label" class="flex flex-col items-center">
                <div
                  class="relative w-full max-w-[140px]"
                  role="img"
                  :aria-label="`${item.label}: ${item.value} percent`"
                >
                  <svg viewBox="0 0 120 120" class="h-auto w-full">
                    <circle cx="60" cy="60" r="52" fill="none" stroke-width="10" class="stroke-slate-100" />
                    <circle
                      cx="60"
                      cy="60"
                      r="52"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="10"
                      stroke-linecap="round"
                      stroke-dasharray="326.73"
                      :stroke-dashoffset="ringOffset(item.value)"
                      transform="rotate(-90 60 60)"
                      :class="item.ringClass"
                    />
                  </svg>
                  <span class="absolute inset-0 flex items-center justify-center text-2xl font-semibold tracking-tight text-slate-900">
                    {{ item.value }}%
                  </span>
                </div>
                <p class="mt-3 text-center text-xs text-slate-500">{{ item.label }}</p>
              </div>
            </div>
          </section>

          <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
            <h2 class="text-sm font-semibold text-slate-900">Recent Activity</h2>
            <p v-if="dashboard.recent_activity.length === 0" class="mt-4 text-sm text-slate-400">No recent activity yet.</p>
            <ol v-else class="relative mt-5 space-y-5 pl-6">
              <span class="absolute bottom-2 left-[3px] top-2 w-px bg-slate-100" aria-hidden="true" />
              <li v-for="(activity, index) in dashboard.recent_activity" :key="index" class="relative">
                <span
                  class="absolute -left-6 top-1.5 h-[7px] w-[7px] rounded-full ring-2 ring-white"
                  :class="activityDotClass(activity.tone)"
                />
                <p class="text-sm text-slate-700">{{ activity.text }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ activity.time ?? '—' }}</p>
              </li>
            </ol>
          </section>
        </div>
      </template>
    </LoadStatus>
  </section>
</template>

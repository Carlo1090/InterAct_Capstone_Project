<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import { categorizeError } from '@/lib/apiError'
import { isNotEnrolledError } from '@/lib/enrollment'
import { useAuthStore } from '@/stores/auth'
import { consumeQueryParam, googleErrorMessage, googleVerifyUrl } from '@/lib/googleAuth'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { CalendarDayStatus, JournalCalendar, StudentDashboard } from '@/types/api'

type StatIcon = 'document' | 'check' | 'clock' | 'alert'

/**
 * `neutral` covers both a non-duty day and a day outside the OJT window — the
 * calendar endpoint returns `no_entry` for both and cannot tell them apart.
 * Neither is the student's fault, so both render slate.
 */
type WeekDayState = 'logged' | 'missed' | 'today' | 'draft' | 'upcoming' | 'neutral'

type WeekDay = {
  date: string
  initial: string
  weekday: string
  tooltip: string
  state: WeekDayState
  segmentClass: string
}

type StatCard = {
  label: string
  value: string
  sub: string
  cardClass: string
  tileClass: string
  icon: StatIcon
  barClass: string
  // null means this stat has no honest denominator, so it gets no bar at all.
  barPercent: number | null
}

const auth = useAuthStore()

// The sidebar's own "Write Daily Journal" target — kept identical to the nav
// item in StudentLayout.vue rather than re-derived here.
const WRITE_JOURNAL_ROUTE = '/student/write-journal'

const MS_PER_DAY = 86_400_000

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

// Flipped once the data is on screen, so the arcs animate from empty.
const drawn = ref(false)

/**
 * Per-day statuses for the current week, keyed by YYYY-MM-DD.
 *
 * The dashboard endpoint only returns a COUNT (`missing_this_week`), which
 * cannot say which days were missed, so the week strip reads its per-day detail
 * from the journal calendar instead. That endpoint is the right source for more
 * than convenience: its `status` already applies the batch's working-day rule
 * and the "today is not over yet" rule server-side, so `missing` is only ever
 * returned for a past DUTY day. Deriving red days here from dates alone would
 * mean re-implementing `BatchWorkingDays` on the client without the
 * `working_days_per_week` field, which is not exposed.
 *
 * Its own loading/error state, so a failure degrades the strip to the
 * count-based bar rather than blanking the panel.
 */
const calendarStatuses = ref(new Map<string, CalendarDayStatus>())
const isWeekLoading = ref(true)
const weekError = ref('')

const hasPerDayData = computed(() => !weekError.value && calendarStatuses.value.size > 0)

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  notEnrolled.value = false

  try {
    const { data } = await api.get<StudentDashboard>('/api/student/dashboard')
    dashboard.value = data
    // Deliberately not awaited: the rest of the dashboard paints immediately
    // and the strip upgrades from its skeleton when this lands.
    void loadWeekDays(data.week)
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

// The single choke point that keeps NaN/Infinity out of every arc, bar and
// label on this page.
const clampPercent = (value: number): number => {
  if (!Number.isFinite(value)) return 0

  return Math.min(100, Math.max(0, value))
}

const safeCount = (value: number | undefined): number => {
  if (value === undefined || !Number.isFinite(value)) return 0

  return Math.max(0, Math.trunc(value))
}

// week.start/week.end are plain YYYY-MM-DD strings (Carbon's toDateString()),
// so read the parts directly — new Date(string) would drag the browser's
// timezone in and can land on the wrong day.
const parseDateString = (value: string | undefined): number | null => {
  const parts = (value ?? '').split('-')
  if (parts.length !== 3) return null

  const [year, month, day] = parts.map(Number)
  if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) return null

  return Date.UTC(year, month - 1, day)
}

/**
 * Format a UTC millisecond value back to YYYY-MM-DD.
 *
 * Every read is `getUTC*`, matching `parseDateString`'s `Date.UTC` — the pair
 * round-trips a calendar date with no timezone involved at any point. Using
 * `getFullYear`/`getDate` here would re-introduce exactly the UTC+8 off-by-one
 * the parser exists to avoid.
 */
const toIsoDate = (utcMs: number): string => {
  const date = new Date(utcMs)
  const month = String(date.getUTCMonth() + 1).padStart(2, '0')
  const day = String(date.getUTCDate()).padStart(2, '0')

  return `${date.getUTCFullYear()}-${month}-${day}`
}

const MONTH_ABBR = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const DAY_INITIALS = ['M', 'T', 'W', 'T', 'F', 'S', 'S']
const WEEKDAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const WEEKDAY_SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

// The strip is built as Monday + N, so the weekday of each index is fixed by
// construction — no need to read it back off a Date and risk a locale surprise.
const shortDate = (iso: string): string => {
  const [, month, day] = iso.split('-').map(Number)

  return `${MONTH_ABBR[month - 1] ?? '?'} ${day}`
}

const STATE_TEXT: Record<WeekDayState, string> = {
  logged: 'entry submitted',
  missed: 'no entry',
  today: 'no entry yet (today)',
  draft: 'draft not submitted',
  upcoming: 'upcoming',
  neutral: 'no entry expected',
}

const STATE_SEGMENT: Record<WeekDayState, string> = {
  logged: 'bg-emerald-500',
  missed: 'bg-rose-500',
  today: 'bg-amber-400 ring-2 ring-amber-200',
  draft: 'bg-amber-400',
  upcoming: 'bg-slate-200',
  neutral: 'bg-slate-200',
}

/**
 * Rose is reserved EXCLUSIVELY for the server's own `missing`. That status is
 * only produced for a past day that passed `BatchWorkingDays::isWorkingDay()`,
 * so both hard rules — today is never red, a non-duty day is never red — hold
 * by construction rather than by a client-side date check that could drift.
 *
 * `draft` is returned both for today with nothing written yet and for a past
 * day carrying an unsubmitted draft; only the first is `today`.
 */
const stateFor = (status: CalendarDayStatus | undefined, date: string, todayIso: string): WeekDayState => {
  if (status === 'submitted') return 'logged'
  if (status === 'missing') return 'missed'
  if (status === 'draft') return date === todayIso ? 'today' : 'draft'
  if (status === 'future') return 'upcoming'
  if (status === 'no_entry') return 'neutral'

  // No calendar row for this date (request failed, or the month was not
  // covered). Fall back to position alone — never invent a missed day.
  return date > todayIso ? 'upcoming' : 'neutral'
}

// The full Mon–Sun week, so the seven segments line up with their M T W T F S S
// initials and future days have something to represent. `week.end` is TODAY,
// not Sunday, so it is used only to locate today — never as the strip's end.
const weekDays = computed<WeekDay[]>(() => {
  const week = dashboard.value?.week
  const startMs = parseDateString(week?.start)
  if (startMs === null || !week) return []

  const todayIso = week.end

  return Array.from({ length: 7 }, (_, index): WeekDay => {
    const date = toIsoDate(startMs + index * MS_PER_DAY)
    const state = stateFor(calendarStatuses.value.get(date), date, todayIso)

    return {
      date,
      initial: DAY_INITIALS[index] ?? '',
      weekday: WEEKDAY_NAMES[index] ?? '',
      tooltip: `${WEEKDAY_SHORT[index]}, ${shortDate(date)} — ${STATE_TEXT[state]}`,
      state,
      segmentClass: STATE_SEGMENT[state],
    }
  })
})

const weekSummary = computed(() => {
  const days = weekDays.value

  return {
    logged: days.filter((day) => day.state === 'logged').length,
    // Days that have counted so far: an entry exists, or one was expected and
    // is now due. Upcoming and non-duty days are excluded from the denominator.
    counted: days.filter((day) => day.state !== 'upcoming' && day.state !== 'neutral').length,
    missed: days.filter((day) => day.state === 'missed'),
    todayPending: days.some((day) => day.state === 'today'),
  }
})

const missedMessage = computed<string | null>(() => {
  const missed = weekSummary.value.missed
  if (missed.length === 0) return null

  if (missed.length === 1) {
    const day = missed[0]
    const todayMs = parseDateString(dashboard.value?.week.end)
    const isYesterday = todayMs !== null && day.date === toIsoDate(todayMs - MS_PER_DAY)

    return isYesterday
      ? `You have no entry for yesterday (${day.weekday}).`
      : `You have no entry for ${day.weekday}.`
  }

  const names = missed.map((day) => day.weekday)
  const last = names.pop()

  return `You have no entries for ${names.join(', ')} and ${last}.`
})

/**
 * A colour KEY, not a state list — which is why one entry can cover more than
 * one state: `today` and `draft` are both amber-400, `upcoming` and `neutral`
 * are both slate-200. Grouping them is what keeps a swatch from being shown at
 * half opacity while that exact colour is on screen.
 *
 * All four always render, in this fixed order. Filtering to the states present
 * meant a student with a blank week — precisely the one who needs the key —
 * never learned what green meant. Absent states dim to `opacity-50` instead.
 */
const LEGEND: { key: string; label: string; dot: string; states: WeekDayState[] }[] = [
  { key: 'logged', label: 'Logged', dot: 'bg-emerald-500', states: ['logged'] },
  { key: 'missed', label: 'Missed', dot: 'bg-rose-500', states: ['missed'] },
  { key: 'today', label: 'Today', dot: 'bg-amber-400', states: ['today', 'draft'] },
  { key: 'neutral', label: 'No entry expected', dot: 'bg-slate-200', states: ['neutral', 'upcoming'] },
]

const weekLegend = computed(() =>
  LEGEND.map((item) => ({
    ...item,
    present: weekDays.value.some((day) => item.states.includes(day.state)),
  })),
)

/**
 * A Mon–Sun week can straddle two months, and the calendar endpoint is scoped
 * to one month per request — so fetch every month the week touches (one call
 * most weeks, two at a month boundary) and merge them.
 */
const loadWeekDays = async (week: { start: string; end: string }): Promise<void> => {
  isWeekLoading.value = true
  weekError.value = ''

  const startMs = parseDateString(week.start)
  if (startMs === null) {
    isWeekLoading.value = false

    return
  }

  const months = [
    ...new Set(Array.from({ length: 7 }, (_, index) => toIsoDate(startMs + index * MS_PER_DAY).slice(0, 7))),
  ]

  try {
    const responses = await Promise.all(
      months.map((month) => api.get<JournalCalendar>('/api/student/journal-calendar', { params: { month } })),
    )

    const next = new Map<string, CalendarDayStatus>()
    for (const { data } of responses) {
      for (const day of data.days) next.set(day.date, day.status)
    }
    calendarStatuses.value = next
  } catch (error) {
    weekError.value = categorizeError(error, 'Unable to load this week’s entries.').message
  } finally {
    isWeekLoading.value = false
  }
}

// week.end is TODAY, not Sunday, so this spans Monday→today: 1-7 days. Zero
// means the range is missing or malformed, which hides the strip entirely.
const daysInWeek = computed(() => {
  const week = dashboard.value?.week
  const start = parseDateString(week?.start)
  const end = parseDateString(week?.end)
  if (start === null || end === null) return 0

  const days = Math.round((end - start) / MS_PER_DAY) + 1

  return Number.isFinite(days) && days > 0 ? days : 0
})

const weekStrip = computed(() => {
  const days = daysInWeek.value
  const missing = safeCount(dashboard.value?.stats.missing_this_week)

  return { days, logged: Math.min(days, Math.max(0, days - missing)) }
})

const weeklyBreakdown = computed(() => {
  const approved = safeCount(dashboard.value?.stats.weekly_logs_approved)
  const pending = safeCount(dashboard.value?.stats.weekly_logs_pending)
  const total = approved + pending

  return {
    approved,
    pending,
    total,
    approvedShare: total > 0 ? clampPercent((approved / total) * 100) : 0,
    pendingShare: total > 0 ? clampPercent((pending / total) * 100) : 0,
  }
})

const ojtPercent = computed(() => clampPercent(dashboard.value?.progress.ojt_duration_percent ?? 0))

// Not the donut's split — this is approvals over weeks elapsed, which is a
// different number, so it gets its own labelled caption line.
const approvalRatePercent = computed(() =>
  clampPercent(dashboard.value?.progress.weekly_reports_approved_percent ?? 0),
)

const stats = computed<StatCard[]>(() => {
  if (!dashboard.value) return []
  const s = dashboard.value.stats
  const { approved, pending, total } = weeklyBreakdown.value
  const days = daysInWeek.value

  return [
    {
      label: 'Entries Submitted',
      value: String(s.entries_submitted_total),
      sub: 'All time',
      cardClass: 'bg-blue-50/40',
      tileClass: 'bg-white ring-1 ring-slate-200/70 text-blue-600',
      icon: 'document',
      barClass: 'bg-blue-500',
      barPercent: null,
    },
    {
      label: 'Weekly Reports Approved',
      value: String(s.weekly_logs_approved),
      sub: 'By supervisor',
      cardClass: 'bg-emerald-50/40',
      tileClass: 'bg-white ring-1 ring-slate-200/70 text-emerald-600',
      icon: 'check',
      barClass: 'bg-emerald-500',
      barPercent: total > 0 ? clampPercent((approved / total) * 100) : 0,
    },
    {
      label: 'Pending Review',
      value: String(s.weekly_logs_pending),
      sub: 'Awaiting supervisor',
      cardClass: 'bg-amber-50/40',
      tileClass: 'bg-white ring-1 ring-slate-200/70 text-amber-600',
      icon: 'clock',
      barClass: 'bg-amber-500',
      barPercent: total > 0 ? clampPercent((pending / total) * 100) : 0,
    },
    {
      label: 'Missing Entries',
      value: String(s.missing_this_week),
      sub: 'This week',
      cardClass: 'bg-rose-50/40',
      tileClass: 'bg-white ring-1 ring-slate-200/70 text-rose-600',
      icon: 'alert',
      barClass: 'bg-rose-500',
      barPercent: days > 0 ? clampPercent((safeCount(s.missing_this_week) / days) * 100) : 0,
    },
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

onMounted(async () => {
  verifiedJustNow.value = consumeQueryParam('email_verified') === '1'
  emailErrorMessage.value = googleErrorMessage(consumeQueryParam('email_error'))

  // The callback wrote email_verified_at server-side; refresh the cached user
  // so the banner disappears without a manual reload.
  if (verifiedJustNow.value) {
    await auth.fetchUser()
  }

  await load()

  // The arcs only enter the DOM once load() resolves — LoadStatus renders the
  // loading branch until then — so flip this afterwards, and let the browser
  // paint the empty state once first or there is nothing to transition from.
  await nextTick()
  requestAnimationFrame(() => {
    drawn.value = true
  })
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
      class="flex flex-col gap-3 rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/70 sm:flex-row sm:items-center sm:justify-between"
    >
      <div class="flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 shrink-0 text-slate-400">
          <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
          <path d="M12 11v5M12 8h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
        </svg>
        <p class="text-sm text-slate-600">
          Your email is <strong class="font-semibold text-slate-700">not verified</strong>.
        </p>
        <TooltipWrap
          label="Journal reminders are only emailed to a verified address — right now you get in-app notifications only. Verifying also lets you sign in with Google."
          placement="bottom"
          class="shrink-0"
        >
          <span
            aria-label="Journal reminders are only emailed to a verified address — right now you get in-app notifications only. Verifying also lets you sign in with Google."
            class="flex h-5 w-5 items-center justify-center rounded-full text-slate-400"
            tabindex="0"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
              <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
              <path d="M9.8 9.6a2.2 2.2 0 1 1 2.9 2.1c-.5.2-.7.6-.7 1.1v.4M12 16.4h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
            </svg>
          </span>
        </TooltipWrap>
      </div>
      <TooltipWrap label="Verify your email with Google" placement="bottom" class="shrink-0">
      <button
        type="button"
        aria-label="Verify your email with Google"
        class="flex shrink-0 items-center justify-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 transition hover:bg-slate-50"
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
      </TooltipWrap>
    </div>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <NotEnrolledNotice v-if="notEnrolled" />

      <template v-else-if="dashboard">
        <div
          v-if="dashboard.stats.missing_this_week > 0"
          class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-100"
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
              <TooltipWrap label="Write today's journal entry" placement="bottom">
                <RouterLink
                  :to="WRITE_JOURNAL_ROUTE"
                  aria-label="Write today's journal entry"
                  class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
                >
                  Write Daily Journal
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                    <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </RouterLink>
              </TooltipWrap>
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
            class="flex h-full flex-col rounded-xl p-6 ring-1 ring-slate-200/60"
            :class="stat.cardClass"
          >
            <div class="flex items-center gap-3">
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="stat.tileClass">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                  <g v-if="stat.icon === 'document'">
                    <path d="M7 3.5h6.5L18 8v12.5H7z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    <path d="M13 3.5V8h5M9.5 12.5h6M9.5 16h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                  </g>
                  <g v-else-if="stat.icon === 'check'">
                    <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
                    <path d="M8.5 12.3l2.4 2.4 4.6-4.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                  </g>
                  <g v-else-if="stat.icon === 'clock'">
                    <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
                    <path d="M12 7.5V12l3 1.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                  </g>
                  <g v-else>
                    <path d="M12 8v4.5M12 16h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                    <path d="M10.3 4.4 3.1 17.1a2 2 0 0 0 1.7 3h14.4a2 2 0 0 0 1.7-3L13.7 4.4a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                  </g>
                </svg>
              </span>
              <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ stat.label }}</p>
            </div>

            <p class="mt-4 text-3xl font-semibold tracking-tight text-slate-900">{{ stat.value }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ stat.sub }}</p>

            <div v-if="stat.barPercent !== null" class="mt-auto pt-5">
              <div class="h-1 overflow-hidden rounded-full bg-slate-200/80">
                <div class="h-full rounded-full" :class="stat.barClass" :style="{ width: `${stat.barPercent}%` }" />
              </div>
            </div>
          </article>
        </div>

        <div>
          <h3 class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">Your progress</h3>
          <div class="grid items-stretch gap-6 md:grid-cols-2 xl:grid-cols-3">
          <section class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
            <h2 class="text-sm font-semibold text-slate-900">OJT Duration</h2>
            <p class="mt-1 text-xs text-slate-400">How far through your batch's planned dates you are.</p>
            <div
              class="mx-auto mt-5 w-full max-w-[180px]"
              role="img"
              :aria-label="`OJT duration progress: ${ojtPercent} percent`"
            >
              <svg viewBox="0 0 160 96" class="h-auto w-full">
                <defs>
                  <linearGradient id="gauge-ojt" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0%" stop-color="#fbbf24" />
                    <stop offset="100%" stop-color="#f97316" />
                  </linearGradient>
                </defs>
                <path
                  d="M 20 80 A 60 60 0 0 1 140 80"
                  fill="none"
                  stroke="#f1f5f9"
                  stroke-width="14"
                  stroke-linecap="round"
                  pathLength="100"
                />
                <path
                  class="draw-offset"
                  d="M 20 80 A 60 60 0 0 1 140 80"
                  fill="none"
                  stroke="url(#gauge-ojt)"
                  stroke-width="14"
                  stroke-linecap="round"
                  pathLength="100"
                  stroke-dasharray="100"
                  :stroke-dashoffset="drawn ? 100 - ojtPercent : 100"
                />
              </svg>
              <div class="-mt-7 text-center">
                <p class="text-3xl font-semibold tracking-tight text-slate-900">{{ ojtPercent }}%</p>
                <p class="mt-1 text-xs text-slate-400">
                  Start date · {{ dashboard.internship.start_date ?? '—' }}
                </p>
              </div>
            </div>
          </section>

          <section class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
            <h2 class="text-sm font-semibold text-slate-900">Weekly Reports</h2>
            <p class="mt-1 text-xs text-slate-400">Your submitted weekly reports, by review outcome.</p>
            <div
              class="relative mx-auto mt-5 w-full max-w-[180px]"
              role="img"
              :aria-label="`Weekly reports: ${weeklyBreakdown.approved} approved, ${weeklyBreakdown.pending} pending`"
            >
              <svg viewBox="0 0 120 120" class="h-auto w-full">
                <g transform="rotate(-90 60 60)">
                  <circle
                    cx="60"
                    cy="60"
                    r="48"
                    fill="none"
                    stroke="#f1f5f9"
                    stroke-width="14"
                    stroke-linecap="round"
                    pathLength="100"
                  />
                  <circle
                    v-if="weeklyBreakdown.approvedShare > 0"
                    class="draw-dash"
                    cx="60"
                    cy="60"
                    r="48"
                    fill="none"
                    stroke="#10b981"
                    stroke-width="14"
                    stroke-linecap="round"
                    pathLength="100"
                    :stroke-dasharray="`${drawn ? weeklyBreakdown.approvedShare : 0} 100`"
                  />
                  <circle
                    v-if="weeklyBreakdown.pendingShare > 0"
                    class="draw-dash"
                    cx="60"
                    cy="60"
                    r="48"
                    fill="none"
                    stroke="#f59e0b"
                    stroke-width="14"
                    stroke-linecap="round"
                    pathLength="100"
                    :stroke-dasharray="`${drawn ? weeklyBreakdown.pendingShare : 0} 100`"
                    :stroke-dashoffset="-weeklyBreakdown.approvedShare"
                  />
                </g>
              </svg>
              <div class="absolute inset-0 flex flex-col items-center justify-center">
                <p class="text-2xl font-semibold tracking-tight text-slate-900">{{ weeklyBreakdown.total }}</p>
                <p class="text-xs text-slate-400">Total</p>
              </div>
            </div>

            <div v-if="weeklyBreakdown.total > 0" class="mt-5 space-y-2">
              <div class="flex items-center gap-2 text-sm">
                <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-500" />
                <span class="text-slate-600">Approved</span>
                <span class="ml-auto font-semibold text-slate-900">{{ weeklyBreakdown.approved }}</span>
              </div>
              <div class="flex items-center gap-2 text-sm">
                <span class="h-2 w-2 shrink-0 rounded-full bg-amber-500" />
                <span class="text-slate-600">Pending</span>
                <span class="ml-auto font-semibold text-slate-900">{{ weeklyBreakdown.pending }}</span>
              </div>
              <p class="pt-1 text-xs text-slate-400">
                {{ approvalRatePercent }}% approval rate over weeks elapsed
              </p>
            </div>
            <p v-else class="mt-5 text-sm text-slate-400">No weekly reports yet</p>
          </section>

          <section
            v-if="weekStrip.days > 0"
            class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70"
          >
            <h2 class="text-sm font-semibold text-slate-900">This Week</h2>
            <p class="mt-1 text-xs text-slate-400">Your entries from Monday to Sunday.</p>

            <!--
              Per-day detail comes from the journal calendar. If that request
              fails the panel degrades to the original count-based bar rather
              than blanking, since the dashboard already knows the total.
            -->
            <template v-if="hasPerDayData">
              <p class="mt-5 text-sm text-slate-600">
                <span class="font-semibold text-slate-900">{{ weekSummary.logged }}</span>
                of {{ weekSummary.counted }} days logged
              </p>

              <div class="mt-3 flex gap-1.5">
                <TooltipWrap
                  v-for="day in weekDays"
                  :key="day.date"
                  :label="day.tooltip"
                  placement="top"
                  class="flex-1"
                >
                  <span
                    class="block h-2.5 w-full rounded-full"
                    :class="day.segmentClass"
                    role="img"
                    :aria-label="day.tooltip"
                  />
                </TooltipWrap>
              </div>

              <div class="mt-1.5 flex gap-1.5" aria-hidden="true">
                <span
                  v-for="day in weekDays"
                  :key="day.date"
                  class="flex-1 text-center text-[10px] text-slate-400"
                >
                  {{ day.initial }}
                </span>
              </div>

              <p v-if="missedMessage" class="mt-4 text-sm text-rose-700">{{ missedMessage }}</p>
              <p v-else class="mt-4 text-sm text-emerald-700">You're on track this week.</p>

              <p v-if="weekSummary.todayPending" class="mt-1.5 text-sm text-amber-700">
                Today's entry isn't written yet.
                <RouterLink :to="WRITE_JOURNAL_ROUTE" class="font-medium underline underline-offset-2 hover:text-amber-900">
                  Write today's entry →
                </RouterLink>
              </p>

              <!-- Always all four, in a fixed order; absent ones dim rather than vanish. -->
              <ul class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5">
                <li
                  v-for="item in weekLegend"
                  :key="item.key"
                  class="flex items-center gap-1.5 text-xs text-slate-500"
                  :class="!item.present && 'opacity-50'"
                >
                  <span class="h-2 w-2 shrink-0 rounded-full" :class="item.dot" />
                  {{ item.label }}
                </li>
              </ul>
            </template>

            <template v-else>
              <p class="mt-5 text-sm text-slate-600">
                <span class="font-semibold text-slate-900">{{ weekStrip.logged }}</span>
                of {{ weekStrip.days }} days logged
              </p>
              <div class="mt-3 flex gap-1.5">
                <span
                  v-for="day in weekStrip.days"
                  :key="day"
                  class="h-2.5 flex-1 rounded-full"
                  :class="[
                    day <= weekStrip.logged ? 'bg-emerald-500' : 'bg-slate-200',
                    isWeekLoading && 'animate-pulse',
                  ]"
                />
              </div>
              <p v-if="weekError" class="mt-4 text-sm text-slate-500">
                Day-by-day detail is unavailable right now.
              </p>
            </template>

            <p class="mt-3 text-xs text-slate-400">
              {{ dashboard.week.start }} – {{ dashboard.week.end }}
            </p>
          </section>
          </div>
        </div>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <h2 class="text-sm font-semibold text-slate-900">Recent Activity</h2>
          <p class="mt-1 text-xs text-slate-400">Your own last five actions in the system.</p>
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
      </template>
    </LoadStatus>
  </section>
</template>

<style scoped>
/* The gauge fills by retracting its dash offset; the donut segments grow their
   dash length in place, since their offset is what positions them on the ring. */
.draw-offset {
  transition: stroke-dashoffset 700ms cubic-bezier(0.4, 0, 0.2, 1);
}

.draw-dash {
  transition: stroke-dasharray 700ms cubic-bezier(0.4, 0, 0.2, 1);
}

@media (prefers-reduced-motion: reduce) {
  .draw-offset,
  .draw-dash {
    transition: none;
  }
}
</style>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { showToast } from '@/lib/toast'
import {
  currentPosition,
  isPermissionDenied,
  locationErrorMessage,
  punchAtSite,
} from '@/lib/dtr'
import LoadStatus from '@/components/LoadStatus.vue'
import ToastHost from '@/components/ToastHost.vue'
import QrScannerModal from '@/components/dtr/QrScannerModal.vue'

/**
 * The student's own view of their Daily Time Record, and where they time in
 * and out.
 *
 * The action here still REQUIRES a scanned code plus coordinates — the scanner
 * opens the camera, and nothing is recorded until a real clock-in QR is in
 * frame. There is deliberately no plain "Clock Out" button that skips the
 * scan: one could be pressed from anywhere, which would quietly undo the whole
 * point of the geofence.
 *
 * A printed code opened with the phone's own camera app still works and lands
 * on /student/dtr/scan. That path is the fallback whenever camera permission
 * here is refused.
 */

type DtrSession = {
  id: number
  work_date: string | null
  site: string | null
  time_in: string | null
  time_out: string | null
  minutes_worked: number | null
  status: 'open' | 'closed' | 'flagged' | 'void'
  adjustment_reason: string | null
}

type DtrPayload = {
  enabled: boolean
  message?: string
  company?: string | null
  open_session?: DtrSession | null
  week?: { start: string; end: string; minutes: number }
  sessions?: DtrSession[]
  progress?: {
    minutes_completed: number
    hours_completed: number
    hours_required: number | null
    hours_percent: number | null
  }
}

const data = ref<DtrPayload | null>(null)
const loading = ref(true)
const error = ref('')

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get<DtrPayload>('/api/student/dtr')
    data.value = response.data
  } catch (err) {
    error.value = categorizeError(err, 'Your time record could not be loaded.').message
  } finally {
    loading.value = false
  }
}

const scannerOpen = ref(false)
const punching = ref(false)
const punchError = ref('')
/** Set when a forgotten session had to be closed to make this clock-in possible. */
const autoClosedNotice = ref('')

/**
 * A code was read AND confirmed in the scanner. Everything after this point is
 * the same path the QR landing page uses — shared in lib/dtr.ts so the two
 * surfaces cannot drift on how a scan becomes a recorded punch.
 *
 * The scanner has already resolved the site and shown the student what this
 * will do, so by here the punch is a deliberate act rather than a side effect
 * of a camera glimpsing a code.
 */
const onConfirmed = async (token: string) => {
  scannerOpen.value = false
  punching.value = true
  punchError.value = ''
  autoClosedNotice.value = ''

  let position: GeolocationPosition

  try {
    position = await currentPosition()
  } catch (error) {
    punching.value = false
    punchError.value = isPermissionDenied(error)
      ? 'Location access is blocked. Clocking in needs it to confirm you are at the workplace — allow it for this site, then scan again.'
      : locationErrorMessage(error)
    return
  }

  try {
    const result = await punchAtSite(token, position)

    if (result.auto_closed_previous) {
      // Too consequential for a toast that disappears: a day the student
      // believed was banked has become a flagged row needing their
      // supervisor. It stays on screen until they scan again.
      autoClosedNotice.value = result.message
    } else {
      showToast(result.message, 'success')
    }

    // Re-fetch rather than patching locally: the server owns whether that was
    // a clock-in or a clock-out, and the hours total moves with it.
    await load()
  } catch (error) {
    punchError.value = categorizeError(error, 'Your clock-in could not be recorded.').message
  } finally {
    punching.value = false
  }
}

const progress = computed(() => data.value?.progress ?? null)

// The server is the authority on which way the next punch goes; this only
// labels the button to match.
const actionLabel = computed(() =>
  data.value?.open_session ? 'Scan to Time Out' : 'Scan to Time In',
)

// Clamped before it reaches the SVG, per the project's gauge convention.
const percent = computed(() => Math.max(0, Math.min(100, progress.value?.hours_percent ?? 0)))
const hasDenominator = computed(() => (progress.value?.hours_required ?? 0) > 0)

const formatHours = (minutes: number | null | undefined): string => {
  if (minutes == null) return '—'
  const hours = Math.floor(minutes / 60)
  const remainder = minutes % 60
  return remainder === 0 ? `${hours}h` : `${hours}h ${remainder}m`
}

/**
 * A `date`-cast column arrives as midnight UTC ("2026-08-20T00:00:00.000000Z").
 * Parsing it can land a day earlier once APP_TIMEZONE is Asia/Manila, so the
 * project's rule is to slice the leading 10 characters instead.
 */
const formatDate = (value: string | null): string => (value ? value.slice(0, 10) : '—')

// A real instant with a timezone marker — the one case a Date is correct.
const formatTime = (value: string | null): string =>
  value ? new Date(value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—'

const statusTone = (status: DtrSession['status']): string =>
  ({
    open: 'bg-blue-50 text-blue-700 ring-blue-200/70',
    closed: 'bg-emerald-50 text-emerald-700 ring-emerald-200/70',
    flagged: 'bg-amber-50 text-amber-800 ring-amber-200/70',
    void: 'bg-slate-100 text-slate-500 ring-slate-200/70',
  })[status]

const statusLabel = (status: DtrSession['status']): string =>
  ({ open: 'In progress', closed: 'Counted', flagged: 'Needs review', void: 'Not counted' })[status]

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <ToastHost />

    <QrScannerModal v-if="scannerOpen" @close="scannerOpen = false" @confirmed="onConfirmed" />

    <LoadStatus :loading="loading" :error="error" :retry="load">
      <div v-if="data && !data.enabled" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
        <h2 class="text-sm font-semibold text-slate-900">Daily Time Record is not in use for your batch</h2>
        <p class="mt-2 text-sm text-slate-600">
          {{ data.message ?? 'Your coordinator has not enabled QR clock-in for this programme.' }}
        </p>
      </div>

      <template v-else-if="data">
        <!--
          A forgotten session had to be closed before this clock-in could
          happen. Deliberately a persistent notice rather than a toast: a day
          the student believed they had banked is now waiting on their
          supervisor, and that must not scroll past in three seconds.
        -->
        <div
          v-if="autoClosedNotice"
          class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200/70"
        >
          <svg viewBox="0 0 24 24" class="mt-0.5 h-4 w-4 shrink-0" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" />
            <path d="M12 8v5M12 16h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
          <span class="flex-1">{{ autoClosedNotice }}</span>
          <button
            type="button"
            class="shrink-0 rounded-md p-1 text-amber-700 transition hover:bg-amber-100"
            aria-label="Dismiss"
            @click="autoClosedNotice = ''"
          >
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none">
              <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
          </button>
        </div>

        <!-- Open-session notice: the single most actionable thing on the page. -->
        <div
          v-if="data.open_session"
          class="flex items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200/70"
        >
          <span class="relative flex h-2.5 w-2.5 shrink-0">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-500 opacity-60" />
            <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-500" />
          </span>
          <span>
            You are clocked in at <strong>{{ data.open_session.site }}</strong> since
            {{ formatTime(data.open_session.time_in) }}. Scan the same QR code when you leave.
          </span>
        </div>

        <!-- Time in / out -->
        <div class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-slate-200/70">
          <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
            {{ data.open_session ? 'You are currently clocked in' : 'Ready to start your shift' }}
          </p>

          <button
            type="button"
            class="mx-auto mt-4 flex items-center gap-2 rounded-full bg-blue-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
            :disabled="punching"
            @click="scannerOpen = true"
          >
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" aria-hidden="true">
              <path
                d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
              />
              <path d="M4 12h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
            {{ punching ? 'Recording...' : actionLabel }}
          </button>

          <p class="mt-3 text-xs text-slate-400">
            Opens your camera. Your location is checked against the workplace when the code is read.
          </p>

          <p
            v-if="punchError"
            class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-left text-sm text-rose-700 ring-1 ring-rose-200/70"
          >
            {{ punchError }}
          </p>
        </div>

        <!-- Hours progress -->
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <div class="flex flex-wrap items-center gap-6">
            <div class="relative h-32 w-32 shrink-0">
              <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90">
                <circle cx="50" cy="50" r="42" fill="none" stroke="#e2e8f0" stroke-width="10" />
                <!--
                  pathLength="100" re-bases the arc so the radius stops
                  mattering; a zero-length segment is omitted entirely because
                  stroke-linecap="round" would still paint a dot at 0.
                -->
                <circle
                  v-if="hasDenominator && percent > 0"
                  cx="50"
                  cy="50"
                  r="42"
                  fill="none"
                  stroke="url(#gauge-dtr-hours)"
                  stroke-width="10"
                  stroke-linecap="round"
                  pathLength="100"
                  stroke-dasharray="100"
                  :stroke-dashoffset="100 - percent"
                />
                <defs>
                  <linearGradient id="gauge-dtr-hours" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#2563eb" />
                    <stop offset="100%" stop-color="#4f46e5" />
                  </linearGradient>
                </defs>
              </svg>
              <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-semibold tracking-tight text-slate-900">
                  {{ hasDenominator ? `${percent}%` : '—' }}
                </span>
              </div>
            </div>

            <div class="min-w-0 flex-1">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Hours completed</p>
              <p class="mt-1 text-3xl font-semibold tracking-tight text-slate-900">
                {{ progress?.hours_completed ?? 0 }}
                <span v-if="hasDenominator" class="text-lg font-medium text-slate-400">
                  / {{ progress?.hours_required }}
                </span>
              </p>
              <p class="mt-2 text-sm text-slate-500">
                Counted from your clock-ins at
                <span class="font-medium text-slate-700">{{ data.company ?? 'your host company' }}</span
                >. Sessions still open or awaiting your supervisor's review are not included.
              </p>
            </div>
          </div>
        </div>

        <!-- This week -->
        <div>
          <h2 class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">
            This week &middot; {{ formatDate(data.week?.start ?? null) }} to {{ formatDate(data.week?.end ?? null) }}
            <span class="ml-1 text-slate-500">({{ formatHours(data.week?.minutes) }})</span>
          </h2>

          <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70">
            <table class="w-full table-fixed text-left text-sm">
              <colgroup>
                <col class="w-32" />
                <col />
                <col class="w-24" />
                <col class="w-24" />
                <col class="w-24" />
                <col class="w-32" />
              </colgroup>
              <thead>
                <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-400">
                  <th class="px-4 py-3">Date</th>
                  <th class="px-4 py-3">Site</th>
                  <th class="px-4 py-3">In</th>
                  <th class="px-4 py-3">Out</th>
                  <th class="px-4 py-3">Hours</th>
                  <th class="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!data.sessions?.length">
                  <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">
                    No clock-ins recorded this week yet.
                  </td>
                </tr>
                <tr
                  v-for="session in data.sessions"
                  :key="session.id"
                  class="border-b border-slate-100 last:border-0"
                >
                  <td class="px-4 py-3 text-slate-700">{{ formatDate(session.work_date) }}</td>
                  <td class="truncate px-4 py-3 text-slate-700">{{ session.site ?? '—' }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ formatTime(session.time_in) }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ formatTime(session.time_out) }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ formatHours(session.minutes_worked) }}</td>
                  <td class="px-4 py-3">
                    <span
                      class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                      :class="statusTone(session.status)"
                    >
                      {{ statusLabel(session.status) }}
                    </span>
                    <p v-if="session.adjustment_reason" class="mt-1 text-xs text-slate-400">
                      {{ session.adjustment_reason }}
                    </p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p class="mt-3 text-xs text-slate-400">
            You can also point your phone's own camera app at the QR code your supervisor shows at your workplace — it
            opens the same clock-in page, and asks you to confirm before anything is recorded.
          </p>
        </div>
      </template>
    </LoadStatus>
  </div>
</template>

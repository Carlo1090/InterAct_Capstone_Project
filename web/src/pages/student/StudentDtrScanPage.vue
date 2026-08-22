<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { categorizeError } from '@/lib/apiError'
import {
  currentPosition,
  isPermissionDenied,
  locationErrorMessage,
  punchAtSite,
  resolveSite,
  type PunchResult,
  type ScanPreview,
} from '@/lib/dtr'

/**
 * The QR landing page.
 *
 * A supervisor shows a code; the student points their phone's normal camera at
 * it and taps the notification, which opens THIS path with ?s=<token>. This
 * path is the one that always works — it needs no camera permission from us,
 * behaves identically on iOS and Android, and is the fallback whenever the
 * in-app scanner's camera access is refused.
 *
 * The page confirms WHAT the code is and WHO is signed in before asking for
 * location, so a student who scanned the wrong sheet — or whose phone is still
 * signed in as someone else — finds out before anything is written and before
 * a permission prompt appears.
 */

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const token = computed(() => {
  const value = route.query.s
  return typeof value === 'string' ? value : ''
})

const preview = ref<ScanPreview | null>(null)
const loading = ref(true)
const loadError = ref('')

const punching = ref(false)
const punchError = ref('')
const result = ref<PunchResult | null>(null)
// Distinguished from a plain error so the copy can explain the fix rather than
// just reporting failure — a blocked location permission is by far the most
// common way this page fails, and it is not recoverable by retrying.
const locationDenied = ref(false)

const loadPreview = async () => {
  if (!token.value) {
    loading.value = false
    loadError.value = 'This link is missing its site code. Scan the QR code again.'
    return
  }

  loading.value = true
  loadError.value = ''

  try {
    // Shared with the in-app scanner, so the two surfaces cannot disagree
    // about what a scanned code means.
    preview.value = await resolveSite(token.value)
  } catch (error) {
    loadError.value = categorizeError(error, 'That QR code could not be checked.').message
  } finally {
    loading.value = false
  }
}

const punch = async () => {
  punching.value = true
  punchError.value = ''
  locationDenied.value = false

  let position: GeolocationPosition

  try {
    position = await currentPosition()
  } catch (error) {
    punching.value = false

    if (isPermissionDenied(error)) {
      locationDenied.value = true
      return
    }

    punchError.value = locationErrorMessage(error)
    return
  }

  try {
    result.value = await punchAtSite(token.value, position)
  } catch (error) {
    punchError.value = categorizeError(error, 'Your clock-in could not be recorded.').message
  } finally {
    punching.value = false
  }
}

/**
 * "Not you?" — sign out and come straight back to THIS scan URL.
 *
 * The token rides along in ?redirect= so the student does not have to walk back
 * to the QR code and scan it a second time. LoginPage's redirectTarget()
 * accepts only same-origin relative paths, so this cannot become an open
 * redirect.
 */
const switchAccount = async () => {
  await auth.logout()
  await router.push({ path: '/login', query: { redirect: route.fullPath } })
}

const actionLabel = computed(() => {
  if (preview.value?.next_action === 'clock_out') return 'Clock Out'
  return 'Clock In'
})

const formatTime = (value: string | null | undefined): string => {
  if (!value) return '—'
  // A genuine instant carrying a timezone marker — the one case the project's
  // "slice, don't parse" date rule allows a real Date for.
  return new Date(value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

onMounted(loadPreview)
</script>

<template>
  <div class="mx-auto max-w-md space-y-6 py-4">
    <p v-if="loading" class="rounded-xl bg-white p-6 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200/70">
      Checking this QR code...
    </p>

    <div
      v-else-if="loadError"
      class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70"
    >
      <h2 class="text-sm font-semibold text-slate-900">This code did not work</h2>
      <p class="mt-2 text-sm text-slate-600">{{ loadError }}</p>
      <RouterLink
        to="/student/dtr"
        class="mt-4 inline-flex rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
      >
        Go to my time record
      </RouterLink>
    </div>

    <!-- Success -->
    <div
      v-else-if="result"
      class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-slate-200/70"
    >
      <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50">
        <svg viewBox="0 0 24 24" class="h-7 w-7 text-emerald-600" fill="none">
          <path d="m5 12.5 4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </div>
      <h2 class="mt-4 text-xl font-semibold tracking-tight text-slate-900">
        {{ result.action === 'clocked_in' ? 'Clocked in' : 'Clocked out' }}
      </h2>

      <!--
        The receipt names the account. A student who tapped straight through
        still finds out here if the phone was signed in as someone else.
      -->
      <p v-if="result.student_name" class="mt-1 text-sm text-slate-500">
        Recorded for <span class="font-semibold text-slate-700">{{ result.student_name }}</span>
      </p>

      <p
        class="mt-3 rounded-lg px-4 py-3 text-sm"
        :class="
          result.auto_closed_previous
            ? 'bg-amber-50 text-amber-900 ring-1 ring-amber-200/70'
            : 'text-slate-600'
        "
      >
        {{ result.message }}
      </p>

      <dl class="mt-5 grid grid-cols-2 gap-3 text-left">
        <div class="rounded-lg bg-slate-50 px-3 py-2">
          <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Time in</dt>
          <dd class="text-sm font-semibold text-slate-900">{{ formatTime(result.session.time_in) }}</dd>
        </div>
        <div class="rounded-lg bg-slate-50 px-3 py-2">
          <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Time out</dt>
          <dd class="text-sm font-semibold text-slate-900">{{ formatTime(result.session.time_out) }}</dd>
        </div>
      </dl>

      <p class="mt-3 text-xs text-slate-400">
        Recorded {{ result.distance_meters }}m from the registered point.
      </p>

      <RouterLink
        to="/student/dtr"
        class="mt-5 inline-flex rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
      >
        View my time record
      </RouterLink>
    </div>

    <!-- The confirm-then-punch state -->
    <div v-else-if="preview" class="space-y-4">
      <!--
        WHO this punch lands on, shown BEFORE the button.
        The QR opens in whichever browser the phone treats as default, which on
        a shared or borrowed handset may still hold a classmate's session — and
        the scan looks identical either way. Naming the account is what makes
        that visible while it is still fixable.
      -->
      <div class="flex items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200/70">
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-50">
          <svg viewBox="0 0 24 24" class="h-5 w-5 text-blue-600" fill="none" aria-hidden="true">
            <circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.8" />
            <path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
        </div>
        <div class="min-w-0 flex-1">
          <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Recording as</p>
          <p class="truncate text-sm font-semibold text-slate-900">
            {{ preview.student.name ?? 'This account' }}
          </p>
          <p v-if="preview.student.username" class="truncate text-xs text-slate-400">
            {{ preview.student.username
            }}<template v-if="preview.student.student_id_number">
              &middot; {{ preview.student.student_id_number }}</template
            >
          </p>
        </div>
        <button
          type="button"
          class="shrink-0 rounded-full border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
          @click="switchAccount"
        >
          Not you?
        </button>
      </div>

      <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">You are clocking in at</p>
        <h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-900">{{ preview.site.label }}</h2>
        <p v-if="preview.site.company" class="text-sm text-slate-500">{{ preview.site.company }}</p>

        <div
          v-if="preview.next_action === 'blocked_other_site'"
          class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200/70"
        >
          You still have an open session at
          <strong>{{ preview.open_session?.site ?? 'another site' }}</strong>. Ask your supervisor to close
          it before clocking in here.
        </div>

        <template v-else>
          <p
            v-if="preview.next_action === 'clock_out'"
            class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600"
          >
            You clocked in at {{ formatTime(preview.open_session?.time_in) }}. Scanning now will clock you out.
          </p>

          <button
            type="button"
            class="mt-5 w-full rounded-full bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
            :disabled="punching"
            @click="punch"
          >
            {{ punching ? 'Checking your location...' : actionLabel }}
          </button>

          <p class="mt-3 text-center text-xs text-slate-400">
            Your location is checked against this workplace. It is recorded with the punch.
          </p>
        </template>
      </div>

      <div
        v-if="locationDenied"
        class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-rose-200/70"
      >
        <h3 class="text-sm font-semibold text-rose-700">Location access is blocked</h3>
        <p class="mt-2 text-sm text-slate-600">
          Clocking in needs your location to confirm you are at the workplace. Allow location for this site
          in your browser settings, then tap {{ actionLabel }} again.
        </p>
      </div>

      <div
        v-else-if="punchError"
        class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200/70"
      >
        {{ punchError }}
      </div>
    </div>
  </div>
</template>

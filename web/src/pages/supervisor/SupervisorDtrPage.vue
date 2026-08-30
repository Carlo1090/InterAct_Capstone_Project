<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { confirmAction, promptAction, showToast } from '@/lib/toast'
import LoadStatus from '@/components/LoadStatus.vue'
import ToastHost from '@/components/ToastHost.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'

/**
 * The supervisor's DTR surface: clock-in sites (and their printable QR codes)
 * plus the review queue for punches that need a human.
 *
 * Creating a site captures THIS device's coordinates, which is why the button
 * asks for location. That is the anchor every student punch is measured
 * against, so it has to be taken while standing at the workplace.
 */

type Geofence = {
  id: number
  company_id: number
  company: string | null
  label: string
  latitude: number
  longitude: number
  radius_meters: number
  captured_accuracy: number | null
  accuracy_is_poor: boolean
  is_active: boolean
  // Punches taken at this site. Zero is what makes permanent delete lossless,
  // and therefore what makes it available at all.
  sessions_count: number
  scan_url: string
}

type ReviewSession = {
  id: number
  student_name: string | null
  site: string | null
  work_date: string | null
  time_in: string | null
  time_out: string | null
  minutes_worked: number | null
  status: 'open' | 'closed' | 'flagged' | 'void'
  time_in_distance: number | null
  time_in_accuracy: number | null
  adjustment_reason: string | null
}

const geofences = ref<Geofence[]>([])
const companies = ref<{ id: number; name: string }[]>([])
const sessions = ref<ReviewSession[]>([])

const loading = ref(true)
const error = ref('')
const sessionFilter = ref<'needs_attention' | 'open' | 'closed' | 'flagged' | 'void'>('needs_attention')

const creating = ref(false)
const newLabel = ref('')
const newCompanyId = ref<number | null>(null)
const newRadius = ref(150)
const createError = ref('')

const loadGeofences = async () => {
  const response = await api.get<{ geofences: Geofence[]; companies: { id: number; name: string }[] }>(
    '/api/supervisor/dtr/geofences',
  )
  geofences.value = response.data.geofences
  companies.value = response.data.companies
  newCompanyId.value ??= companies.value[0]?.id ?? null
}

const loadSessions = async () => {
  const response = await api.get<{ data: ReviewSession[] }>('/api/supervisor/dtr/sessions', {
    params: { status: sessionFilter.value },
  })
  sessions.value = response.data.data
}

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    await Promise.all([loadGeofences(), loadSessions()])
  } catch (err) {
    error.value = categorizeError(err, 'The time record page could not be loaded.').message
  } finally {
    loading.value = false
  }
}

/**
 * enableHighAccuracy asks for the GPS radio rather than a coarse network fix.
 * The reading taken here becomes the fence centre for every future punch, so a
 * wifi-derived position hundreds of metres out would strand every intern
 * outside their own workplace.
 */
const currentPosition = (): Promise<GeolocationPosition> =>
  new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new Error('unsupported'))
      return
    }

    navigator.geolocation.getCurrentPosition(resolve, reject, {
      enableHighAccuracy: true,
      timeout: 20000,
      maximumAge: 0,
    })
  })

/**
 * Duck-typed rather than `err instanceof GeolocationPositionError` — that
 * global is not reliably defined everywhere, and referencing an undefined
 * identifier inside a catch block throws a ReferenceError that swallows the
 * real error. `code === 1` is PERMISSION_DENIED in the spec.
 */
const isPermissionDenied = (error: unknown): boolean =>
  typeof error === 'object' && error !== null && (error as { code?: number }).code === 1

const createGeofence = async () => {
  createError.value = ''

  if (!newLabel.value.trim()) {
    createError.value = 'Give this site a name, for example "Main Office".'
    return
  }

  if (!newCompanyId.value) {
    createError.value = 'No company is linked to your account yet.'
    return
  }

  creating.value = true

  let position: GeolocationPosition

  try {
    position = await currentPosition()
  } catch (err) {
    creating.value = false
    createError.value = isPermissionDenied(err)
      ? 'Location access is blocked. Allow it for this site, then try again — the QR code has to be anchored to this workplace.'
      : 'Your location could not be read. Try again near a window or outdoors.'
    return
  }

  try {
    const response = await api.post<{ geofence: Geofence; message: string }>(
      '/api/supervisor/dtr/geofences',
      {
        company_id: newCompanyId.value,
        label: newLabel.value.trim(),
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        radius_meters: newRadius.value,
        captured_accuracy:
          position.coords.accuracy != null ? Math.round(position.coords.accuracy) : null,
      },
    )

    geofences.value.unshift(response.data.geofence)
    newLabel.value = ''
    showToast(response.data.message, 'success')

    if (response.data.geofence.accuracy_is_poor) {
      showToast(
        'Your location reading was vague, so this fence may be off. Check it, and re-create the site outdoors if interns cannot clock in.',
        'error',
      )
    }
  } catch (err) {
    createError.value = categorizeError(err, 'The clock-in site could not be created.').message
  } finally {
    creating.value = false
  }
}

/**
 * Widen or tighten an existing fence in place.
 *
 * The API has always supported this; the UI only offered a radius on the
 * CREATE form, so the sole way to change one was retire-and-recreate — which
 * also throws away the printed QR code, since a new site gets a new token.
 * That is a needless amount of collateral for "150m is a bit tight here".
 *
 * Coordinates deliberately remain uneditable (see UpdateGeofenceRequest): a
 * fence may be resized from anywhere, but MOVING one has to mean standing
 * there again.
 */
const updateRadius = async (geofence: Geofence, radius: number) => {
  const previous = geofence.radius_meters
  // Optimistic, so the select does not visibly snap back while the request
  // is in flight; reverted below if the server refuses.
  geofence.radius_meters = radius

  try {
    await api.put(`/api/supervisor/dtr/geofences/${geofence.id}`, { radius_meters: radius })
    showToast(`"${geofence.label}" now allows clock-ins within ${radius}m.`, 'success')
  } catch (err) {
    geofence.radius_meters = previous
    showToast(categorizeError(err, 'The radius could not be changed.').message, 'error')
  }
}

/**
 * Step one of two. Retiring switches the site off — the QR stops working —
 * without touching anything. It is reversible, and the copy now says so,
 * because the old wording read like a delete and made a recoverable action
 * feel final.
 */
const retireGeofence = async (geofence: Geofence) => {
  const confirmed = await confirmAction({
    title: `Retire "${geofence.label}"?`,
    message:
      'Its QR code stops working immediately, and interns can no longer clock in there.\n\n'
      + 'Time records already taken at this site are kept and still count. You can restore the site later '
      + 'from Retired sites, and its existing QR codes will work again.',
    confirmLabel: 'Retire site',
    tone: 'danger',
  })

  if (!confirmed) return

  try {
    await api.delete(`/api/supervisor/dtr/geofences/${geofence.id}`)
    geofence.is_active = false
    // Open the section it just moved into, so it does not appear to vanish.
    retiredOpen.value = true
    showToast('Clock-in site retired. You can restore it from Retired sites.', 'success')
  } catch (err) {
    showToast(categorizeError(err, 'The site could not be retired.').message, 'error')
  }
}

/** Undo a retire. Same token, so codes already handed out start working again. */
const restoreGeofence = async (geofence: Geofence) => {
  const confirmed = await confirmAction({
    title: `Restore "${geofence.label}"?`,
    message:
      'Interns will be able to clock in here again, and any QR code printed from this site before it was '
      + 'retired starts working again.',
    confirmLabel: 'Restore site',
  })

  if (!confirmed) return

  try {
    await api.post(`/api/supervisor/dtr/geofences/${geofence.id}/restore`)
    geofence.is_active = true
    showToast(`"${geofence.label}" is active again.`, 'success')
  } catch (err) {
    showToast(categorizeError(err, 'The site could not be restored.').message, 'error')
  }
}

/**
 * Step two, and the only irreversible action on this page.
 *
 * The confirmation is deliberately heavier than every other one in the app: a
 * type-to-confirm prompt in the danger tone, where the supervisor writes the
 * site's own name back. Every other destructive action here is recoverable —
 * a retire can be restored, an adjusted session can be adjusted again, a void
 * leaves the row — so a single "are you sure?" is proportionate to them. This
 * one erases a row that cannot be brought back, so the gesture is made
 * specific to THIS site rather than a reflex click on a button in a familiar
 * position. It is offered only where the API would allow it (retired, zero
 * punches), so the dialog never asks someone to type a name for an action
 * that then fails.
 */
const deleteGeofenceForever = async (geofence: Geofence) => {
  const typed = await promptAction({
    title: `Permanently delete "${geofence.label}"?`,
    message:
      'This cannot be undone. The site, its coordinates and its QR code are erased, and any printed copy of '
      + `that code becomes permanently dead.\n\nType the site name to confirm: ${geofence.label}`,
    placeholder: geofence.label,
    confirmLabel: 'Delete forever',
    tone: 'danger',
    multiline: false,
    required: true,
    requiredError: 'Type the site name to confirm.',
    // Case- and whitespace-insensitive: the point is to prove the supervisor
    // read WHICH site they are erasing, not to test their typing.
    validate: (value) =>
      value.trim().toLowerCase() === geofence.label.trim().toLowerCase()
        ? null
        : `That does not match. Type "${geofence.label}" exactly to confirm.`,
  })

  if (typed === null) return

  try {
    await api.delete(`/api/supervisor/dtr/geofences/${geofence.id}/permanent`)
    geofences.value = geofences.value.filter((site) => site.id !== geofence.id)
    showToast(`"${geofence.label}" was deleted.`, 'success')
  } catch (err) {
    showToast(categorizeError(err, 'The site could not be deleted.').message, 'error')
  }
}

const downloadQr = (geofence: Geofence, format: 'svg' | 'png') => {
  // A plain navigation rather than an Axios blob: the endpoint already sends
  // Content-Disposition: attachment, and this keeps the session cookie on the
  // request without buffering the file through JS.
  window.open(`/api/supervisor/dtr/geofences/${geofence.id}/qr?format=${format}`, '_blank')
}

const adjustSession = async (session: ReviewSession) => {
  // Prefilled with whatever is already on the row, which for an
  // auto-closed session is the assumed standard shift. Those hours count for
  // nothing while the session is flagged, so the supervisor's job is to
  // confirm or correct the number — and confirming should be one tap, not
  // retyping a figure already on screen.
  const suggested =
    session.minutes_worked != null ? String(Math.round((session.minutes_worked / 60) * 100) / 100) : ''

  const hours = await promptAction({
    title: `Correct ${session.student_name ?? 'this intern'}'s hours`,
    message:
      'How many hours did they actually work this session? A reason is required and is kept on the record.',
    placeholder: 'e.g. 8',
    initialValue: suggested,
    confirmLabel: 'Continue',
    required: true,
    requiredError: 'Enter the number of hours worked.',
  })

  if (hours === null) return

  const parsed = Number(hours)

  if (!Number.isFinite(parsed) || parsed < 0 || parsed > 24) {
    showToast('Enter a number of hours between 0 and 24.', 'error')
    return
  }

  const reason = await promptAction({
    title: 'Reason for the adjustment',
    message: 'This is stored with the record and is visible to the intern and their coordinator.',
    placeholder: 'e.g. Left at 5pm but forgot to scan out',
    confirmLabel: 'Save adjustment',
    required: true,
    requiredError: 'A reason is required — it stays on the record.',
  })

  if (reason === null) return

  try {
    await api.post(`/api/supervisor/dtr/sessions/${session.id}/adjust`, {
      minutes_worked: Math.round(parsed * 60),
      reason: reason.trim(),
    })
    showToast('Session adjusted.', 'success')
    await loadSessions()
  } catch (err) {
    showToast(categorizeError(err, 'The session could not be adjusted.').message, 'error')
  }
}

const voidSession = async (session: ReviewSession) => {
  const reason = await promptAction({
    title: `Discount ${session.student_name ?? 'this intern'}'s session?`,
    message: 'The record is kept but stops counting toward required hours. A reason is required.',
    placeholder: 'e.g. Duplicate scan; the intern was not on site',
    confirmLabel: 'Void session',
    required: true,
    requiredError: 'A reason is required — it stays on the record.',
  })

  if (reason === null) return

  try {
    await api.post(`/api/supervisor/dtr/sessions/${session.id}/void`, { reason: reason.trim() })
    showToast('Session voided.', 'success')
    await loadSessions()
  } catch (err) {
    showToast(categorizeError(err, 'The session could not be voided.').message, 'error')
  }
}

const changeFilter = async (value: typeof sessionFilter.value) => {
  sessionFilter.value = value

  try {
    await loadSessions()
  } catch (err) {
    showToast(categorizeError(err, 'The list could not be loaded.').message, 'error')
  }
}

const activeSites = computed(() => geofences.value.filter((site) => site.is_active))

/**
 * Retired sites used to sit in the same grid as live ones at 60% opacity with
 * every action stripped off — inert cards that could never be removed and only
 * accumulated. They get their own collapsed section instead, where the two
 * things you can actually do to one (put it back, or erase it) live.
 */
const retiredSites = computed(() => geofences.value.filter((site) => !site.is_active))
const retiredOpen = ref(false)

const hasMultipleCompanies = computed(() => companies.value.length > 1)

const formatDate = (value: string | null): string => (value ? value.slice(0, 10) : '—')
const formatTime = (value: string | null): string =>
  value ? new Date(value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—'
const formatHours = (minutes: number | null): string => {
  if (minutes == null) return '—'
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m === 0 ? `${h}h` : `${h}h ${m}m`
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <ToastHost />

    <LoadStatus :loading="loading" :error="error" :retry="load">
      <!-- Create a site -->
      <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
        <div class="flex items-start gap-2">
          <h2 class="text-sm font-semibold text-slate-900">Add a clock-in site</h2>
          <TooltipWrap
            label="The QR code is anchored to wherever you are standing right now. Create it at the workplace, ideally near a window or outdoors for a sharper GPS reading."
          >
            <span
              class="flex h-4 w-4 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500"
              aria-label="The QR code is anchored to wherever you are standing right now. Create it at the workplace, ideally near a window or outdoors for a sharper GPS reading."
            >
              i
            </span>
          </TooltipWrap>
        </div>
        <p class="mt-1 text-sm text-slate-500">
          Turn on location, stand where your interns arrive, then create the site. Show the QR code to your
          interns however suits you &mdash; from this page on your own screen, or from a copy you download.
        </p>

        <div class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto]">
          <input
            v-model="newLabel"
            type="text"
            placeholder="Site name, e.g. Main Office"
            class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
          />
          <label class="flex items-center gap-2 text-sm text-slate-600">
            Radius
            <select
              v-model.number="newRadius"
              class="rounded-lg border border-slate-300 px-2 py-2 text-sm focus:border-blue-500 focus:outline-none"
            >
              <option :value="50">50 m</option>
              <option :value="100">100 m</option>
              <option :value="150">150 m</option>
              <option :value="300">300 m</option>
              <option :value="500">500 m</option>
            </select>
          </label>
          <button
            type="button"
            class="rounded-full bg-blue-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
            :disabled="creating"
            @click="createGeofence"
          >
            {{ creating ? 'Reading location...' : 'Create with my location' }}
          </button>
        </div>

        <label v-if="hasMultipleCompanies" class="mt-3 block text-sm text-slate-600">
          Company
          <select
            v-model.number="newCompanyId"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none sm:w-64"
          >
            <option v-for="company in companies" :key="company.id" :value="company.id">
              {{ company.name }}
            </option>
          </select>
        </label>

        <p v-if="createError" class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">
          {{ createError }}
        </p>
      </div>

      <!-- Sites -->
      <div>
        <h2 class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">
          Clock-in sites ({{ activeSites.length }} active)
        </h2>

        <div v-if="!activeSites.length" class="rounded-xl bg-white p-6 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200/70">
          <template v-if="retiredSites.length">
            No active clock-in sites. Create one above, or restore a retired site below.
          </template>
          <template v-else>
            No clock-in sites yet. Create one above so your interns can start recording time.
          </template>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2">
          <div
            v-for="site in activeSites"
            :key="site.id"
            class="flex h-full flex-col rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70"
          >
            <div class="min-w-0">
              <h3 class="truncate text-sm font-semibold text-slate-900">{{ site.label }}</h3>
              <p class="text-xs text-slate-500">{{ site.company }} &middot; {{ site.radius_meters }}m radius</p>
            </div>

            <p
              v-if="site.accuracy_is_poor"
              class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200/70"
            >
              This site was saved with a vague location reading (±{{ site.captured_accuracy }}m), so the fence
              may be off. If interns cannot clock in, create it again outdoors.
            </p>

            <p class="mt-3 font-mono text-xs text-slate-400">
              {{ site.latitude.toFixed(6) }}, {{ site.longitude.toFixed(6) }}
            </p>

            <label class="mt-3 flex items-center gap-2 text-xs text-slate-600">
              Allowed range
              <select
                :value="site.radius_meters"
                class="rounded-md border border-slate-300 px-2 py-1 text-xs focus:border-blue-500 focus:outline-none"
                @change="updateRadius(site, Number(($event.target as HTMLSelectElement).value))"
              >
                <option :value="50">50 m</option>
                <option :value="100">100 m</option>
                <option :value="150">150 m</option>
                <option :value="300">300 m</option>
                <option :value="500">500 m</option>
              </select>
              <span v-if="site.accuracy_is_poor" class="text-amber-700">widen if interns are refused</span>
            </label>

            <div class="mt-4 flex items-center justify-end gap-2 whitespace-nowrap">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
                @click="downloadQr(site, 'svg')"
              >
                QR (SVG)
              </button>
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
                @click="downloadQr(site, 'png')"
              >
                PNG
              </button>
              <TooltipWrap label="Switch this site off. You can restore it later." placement="top" align="end">
                <button
                  type="button"
                  class="rounded-md px-3 py-1.5 text-sm font-medium text-rose-600 transition hover:bg-rose-50"
                  @click="retireGeofence(site)"
                >
                  Retire
                </button>
              </TooltipWrap>
            </div>
          </div>
        </div>

        <!--
          Retired sites, out of the way but reachable. Collapsed by default:
          they are history, not work — but this is the only place a site can be
          put back or finally erased, so they cannot simply be hidden.
        -->
        <div v-if="retiredSites.length" class="mt-4 rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 px-5 py-3 text-left transition hover:bg-slate-50"
            :aria-expanded="retiredOpen"
            @click="retiredOpen = !retiredOpen"
          >
            <span class="text-xs font-medium uppercase tracking-wide text-slate-400">
              Retired sites ({{ retiredSites.length }})
            </span>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="none"
              class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
              :class="retiredOpen && 'rotate-180'"
            >
              <path d="m6 9.5 6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>

          <ul v-if="retiredOpen" class="divide-y divide-slate-100 border-t border-slate-100">
            <li v-for="site in retiredSites" :key="site.id" class="px-5 py-4">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <h3 class="truncate text-sm font-semibold text-slate-700">{{ site.label }}</h3>
                    <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                      Retired
                    </span>
                  </div>
                  <p class="mt-0.5 text-xs text-slate-500">{{ site.company }} &middot; {{ site.radius_meters }}m radius</p>
                  <p class="mt-1 font-mono text-xs text-slate-400">
                    {{ site.latitude.toFixed(6) }}, {{ site.longitude.toFixed(6) }}
                  </p>
                </div>

                <div class="flex shrink-0 items-center gap-2 whitespace-nowrap">
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
                    @click="restoreGeofence(site)"
                  >
                    Restore
                  </button>
                  <!--
                    Offered ONLY at zero punches, the only case where erasing
                    the row loses nothing. A used site keeps its Delete button
                    hidden and says why in its place, rather than showing a
                    button that answers 422.
                  -->
                  <TooltipWrap
                    v-if="site.sessions_count === 0"
                    label="Erase this site for good. It has no time records, so nothing is lost."
                    placement="top"
                    align="end"
                  >
                    <button
                      type="button"
                      class="rounded-md px-3 py-1.5 text-sm font-medium text-rose-600 transition hover:bg-rose-50"
                      @click="deleteGeofenceForever(site)"
                    >
                      Delete permanently
                    </button>
                  </TooltipWrap>
                </div>
              </div>

              <p v-if="site.sessions_count > 0" class="mt-2 text-xs text-slate-500">
                Kept because {{ site.sessions_count }}
                {{ site.sessions_count === 1 ? 'time record was' : 'time records were' }} taken here — deleting
                the site would strip the location off {{ site.sessions_count === 1 ? 'it' : 'them' }}.
              </p>
            </li>
          </ul>
        </div>
      </div>

      <!-- Review -->
      <div>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-xs font-medium uppercase tracking-wide text-slate-400">Time records</h2>
          <div class="flex gap-2">
            <button
              v-for="option in (['needs_attention', 'open', 'closed', 'flagged', 'void'] as const)"
              :key="option"
              type="button"
              class="rounded-full px-3 py-1 text-xs font-medium transition"
              :class="
                sessionFilter === option
                  ? 'bg-blue-600 text-white'
                  : 'border border-slate-300 text-slate-600 hover:bg-slate-50'
              "
              @click="changeFilter(option)"
            >
              {{ option === 'needs_attention' ? 'Needs attention' : option }}
            </button>
          </div>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70">
          <table class="w-full table-fixed text-left text-sm">
            <colgroup>
              <col />
              <col class="w-28" />
              <col class="w-20" />
              <col class="w-20" />
              <col class="w-24" />
              <col class="w-28" />
              <col class="w-44" />
            </colgroup>
            <thead>
              <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-400">
                <th class="px-4 py-3">Intern</th>
                <th class="px-4 py-3">Date</th>
                <th class="px-4 py-3">In</th>
                <th class="px-4 py-3">Out</th>
                <th class="px-4 py-3">Hours</th>
                <th class="px-4 py-3">Distance</th>
                <th class="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!sessions.length">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">
                  {{
                    sessionFilter === 'needs_attention'
                      ? 'Nothing needs your attention. Sessions left open overnight and unusually long shifts appear here.'
                      : 'No records match this filter.'
                  }}
                </td>
              </tr>
              <tr v-for="session in sessions" :key="session.id" class="border-b border-slate-100 last:border-0">
                <td class="truncate px-4 py-3 text-slate-700">
                  {{ session.student_name }}
                  <span class="block truncate text-xs text-slate-400">{{ session.site }}</span>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ formatDate(session.work_date) }}</td>
                <td class="px-4 py-3 text-slate-700">{{ formatTime(session.time_in) }}</td>
                <td class="px-4 py-3 text-slate-700">{{ formatTime(session.time_out) }}</td>
                <td class="px-4 py-3 text-slate-700">{{ formatHours(session.minutes_worked) }}</td>
                <td class="px-4 py-3 text-slate-500">
                  <span v-if="session.time_in_distance != null">
                    {{ session.time_in_distance }}m
                    <span v-if="session.time_in_accuracy != null" class="block text-xs text-slate-400">
                      ±{{ session.time_in_accuracy }}m
                    </span>
                  </span>
                  <span v-else>—</span>
                </td>
                <td class="px-4 py-3">
                  <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                    <button
                      v-if="session.status !== 'void'"
                      type="button"
                      class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
                      @click="adjustSession(session)"
                    >
                      Adjust
                    </button>
                    <button
                      v-if="session.status !== 'void'"
                      type="button"
                      class="rounded-md px-3 py-1.5 text-sm font-medium text-rose-600 transition hover:bg-rose-50"
                      @click="voidSession(session)"
                    >
                      Void
                    </button>
                    <span v-else class="text-xs text-slate-400">Voided</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </LoadStatus>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { confirmAction, showToast } from '@/lib/toast'
import { useAuthStore } from '@/stores/auth'
import LoadStatus from '@/components/LoadStatus.vue'
import ToastHost from '@/components/ToastHost.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'

/**
 * The coordinator's Daily Time Record surface — monitoring AND the on/off
 * preference that governs whether there is anything to monitor.
 *
 * The preference used to live in the account-menu popover, which meant a
 * coordinator wondering "why is this page empty?" had no way to find out from
 * the page itself. It lives here now, and this page is the only place it is
 * set. When the DTR is off the page explains the consequence and captures WHY,
 * instead of rendering an empty table under a one-line hint.
 *
 * Monitoring is still deliberately read-only: corrections belong to the
 * supervisor who was actually at the workplace, exactly as review verdicts on
 * weekly journals do. What a coordinator genuinely needs is the Sites tab —
 * a supervisor who generated a QR code at home anchored the geofence to their
 * house, and nothing in the automated flow would ever notice.
 */

type HoursRow = {
  student_id: number
  student_name: string | null
  student_id_number: string | null
  company: string | null
  batch: string | null
  hours_completed: number
  hours_required: number | null
  hours_percent: number | null
  open_sessions: number
  flagged_sessions: number
}

type Site = {
  id: number
  label: string
  company: string | null
  company_address: string | null
  latitude: number
  longitude: number
  radius_meters: number
  captured_accuracy: number | null
  accuracy_is_poor: boolean
  is_active: boolean
  map_url: string
}

type ReasonOption = { value: string; label: string }

type Preference = {
  dtr_enabled: boolean
  dtr_disabled_reason: string | null
  dtr_disabled_note: string | null
  reason_options: ReasonOption[]
  affected_students: number
  updated_at: string | null
}

const auth = useAuthStore()

const tab = ref<'hours' | 'sites'>('hours')
const rows = ref<HoursRow[]>([])
const sites = ref<Site[]>([])
const loading = ref(true)
const error = ref('')

const enabled = ref(false)
const reason = ref<string>('')
const note = ref('')
const reasonOptions = ref<ReasonOption[]>([])
const affectedStudents = ref(0)
const changedAt = ref<string | null>(null)
const isSaving = ref(false)
const savedJustNow = ref(false)

const applyPreference = (data: Preference) => {
  enabled.value = data.dtr_enabled
  reason.value = data.dtr_disabled_reason ?? ''
  note.value = data.dtr_disabled_note ?? ''
  reasonOptions.value = data.reason_options
  affectedStudents.value = data.affected_students
  changedAt.value = data.updated_at

  // Keep the cached user in step — nothing else re-fetches it until a reload.
  if (auth.user) auth.user.dtr_enabled = data.dtr_enabled
}

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const [preference, hours, siteList] = await Promise.all([
      api.get<Preference>('/api/coordinator/dtr-preference'),
      api.get<{ rows: HoursRow[] }>('/api/coordinator/dtr'),
      api.get<{ sites: Site[] }>('/api/coordinator/dtr/sites'),
    ])
    applyPreference(preference.data)
    rows.value = hours.data.rows
    sites.value = siteList.data.sites
  } catch (err) {
    error.value = categorizeError(err, 'The time record page could not be loaded.').message
  } finally {
    loading.value = false
  }
}

/**
 * One writer for the whole preference — the switch and the reason fields all
 * PUT the same three values, so the two can never disagree about what the
 * stored preference is.
 */
const savePreference = async (nextEnabled: boolean, options: { silent?: boolean } = {}) => {
  isSaving.value = true

  try {
    const { data } = await api.put<Preference & { message: string }>(
      '/api/coordinator/dtr-preference',
      {
        dtr_enabled: nextEnabled,
        dtr_disabled_reason: reason.value || null,
        dtr_disabled_note: note.value.trim() || null,
      },
    )

    applyPreference(data)

    if (options.silent) {
      savedJustNow.value = true
      window.setTimeout(() => (savedJustNow.value = false), 2500)
    } else {
      showToast(data.message, 'success')
      // Turning it on reveals figures that were never fetched while off.
      await load()
    }
  } catch (err) {
    showToast(categorizeError(err, 'The setting could not be saved.').message, 'error')
  } finally {
    isSaving.value = false
  }
}

const toggle = async () => {
  if (enabled.value) {
    const confirmed = await confirmAction({
      title: 'Turn off the Daily Time Record?',
      message: `${affectedStudents.value} active intern(s) will stop seeing it, and clocked hours will no longer count toward their required hours. Existing records are kept and will return if you switch it back on.`,
      confirmLabel: 'Turn off DTR',
      tone: 'danger',
    })
    if (!confirmed) return
  }

  await savePreference(!enabled.value)
}

// The reason only ever describes an OFF switch, so saving it never flips the
// switch itself — it re-sends the current state alongside the new reason.
const saveReason = () => savePreference(false, { silent: true })

const needsAttention = computed(() =>
  rows.value.filter((row) => row.flagged_sessions > 0 || row.open_sessions > 0).length,
)

// Sliced, never parsed: the API hands back an instant but only the day is
// shown, and slicing cannot drift across the Asia/Manila offset.
const changedOn = computed(() => changedAt.value?.slice(0, 10) ?? null)

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <LoadStatus :loading="loading" :error="error" :retry="load">
      <!-- OFF: the page explains itself instead of rendering an empty table. -->
      <template v-if="!enabled">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <div class="flex items-start gap-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                <path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                <circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.6" />
              </svg>
            </span>
            <div class="min-w-0 flex-1">
              <h2 class="text-base font-semibold text-slate-900">QR clock-in is off for your batches</h2>
              <p class="mt-1.5 max-w-2xl text-sm text-slate-500">
                Nothing on this page collects hours. Your {{ affectedStudents }} active intern{{ affectedStudents === 1 ? '' : 's' }}
                {{ affectedStudents === 1 ? 'has' : 'have' }} no clock-in page, and their supervisors cannot generate a
                code for a site.
              </p>
            </div>
          </div>

          <div class="mt-5 flex items-center justify-between gap-6 rounded-lg border border-slate-200 px-5 py-4">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-slate-900">Use QR clock-in for my batches</p>
              <p class="mt-1 max-w-2xl text-sm text-slate-500">
                Interns time in and out by scanning their site's code &mdash; shown on the supervisor's own screen, or
                from a copy they saved. Their phone reports its location and it is checked against that spot.
              </p>
            </div>
            <button
              type="button"
              role="switch"
              :aria-checked="enabled"
              aria-label="Use QR clock-in for my batches"
              class="flex h-7 w-12.5 shrink-0 items-center rounded-full bg-slate-300 p-0.75 transition disabled:opacity-60"
              :disabled="isSaving"
              @click="toggle"
            >
              <span class="block h-5.5 w-5.5 rounded-full bg-white shadow" />
            </button>
          </div>

          <div class="mt-3 rounded-lg bg-slate-50 px-5 py-4 ring-1 ring-slate-200/70">
            <div class="flex items-center justify-between gap-4">
              <p class="text-sm font-semibold text-slate-900">
                Why it is off
                <span class="font-normal text-slate-400">&mdash; optional, shown to the admin and the next coordinator</span>
              </p>
              <p class="shrink-0 text-xs text-slate-400">
                <span v-if="savedJustNow" class="text-emerald-700">Saved</span>
                <span v-else-if="changedOn">Saved {{ changedOn }}</span>
              </p>
            </div>
            <div class="mt-2.5 flex flex-col gap-3 sm:flex-row">
              <select
                v-model="reason"
                aria-label="Why QR clock-in is off"
                class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm sm:w-80"
                :disabled="isSaving"
                @change="saveReason"
              >
                <option value="">Select a reason</option>
                <option v-for="option in reasonOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <input
                v-model="note"
                type="text"
                maxlength="255"
                aria-label="Note about why QR clock-in is off"
                placeholder="Add a note (optional)"
                class="h-10 w-full flex-1 rounded-md border border-slate-300 bg-white px-3 text-sm"
                :disabled="isSaving"
                @blur="saveReason"
              />
            </div>
          </div>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
          <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Leave it off when</p>
            <ul class="mt-3 space-y-2.5 text-sm text-slate-600">
              <li class="flex gap-2.5">
                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                Interns have no fixed workplace &mdash; field work, home visits, or rotating assignments across
                branches.
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                The department would rather not collect intern location at all.
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                The programme already records hours on a signed sheet the college accepts.
              </li>
            </ul>
            <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-400">
              One code is anchored to one set of coordinates, so it can only ever prove attendance at a single site.
            </p>
          </div>

          <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Where hours come from meanwhile</p>
            <ul class="mt-3 space-y-2.5 text-sm text-slate-600">
              <li class="flex gap-2.5">
                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                Interns type <span class="font-medium text-slate-700">No. of hours</span> themselves on the Weekly and
                Time Log Summary, and the supervisor signs the printed sheet by hand.
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                Their dashboard shows elapsed OJT duration instead of an hours gauge.
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                Nothing is invented either way &mdash; an unclocked hour stays unclocked.
              </li>
            </ul>
          </div>
        </div>

        <p class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-500 ring-1 ring-slate-200/70">
          Sessions clocked before the Daily Time Record was switched off are kept and simply stop counting. Turning it
          back on restores every figure intact.
        </p>
      </template>

      <!-- ON: a one-line status strip, then the monitoring surface. -->
      <template v-else>
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white px-5 py-3.5 shadow-sm ring-1 ring-slate-200/70">
          <div class="flex min-w-0 flex-wrap items-center gap-3">
            <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-600" />
            <p class="text-sm font-semibold text-slate-900">QR clock-in is on for your batches</p>
            <span class="text-sm text-slate-400">
              {{ affectedStudents }} intern{{ affectedStudents === 1 ? '' : 's' }} &middot; {{ sites.length }} clock-in
              site{{ sites.length === 1 ? '' : 's' }}
            </span>
          </div>
          <div class="flex shrink-0 items-center gap-3">
            <span class="text-sm text-slate-500">Turn off</span>
            <button
              type="button"
              role="switch"
              :aria-checked="enabled"
              aria-label="Use QR clock-in for my batches"
              class="flex h-7 w-12.5 shrink-0 items-center justify-end rounded-full bg-blue-600 p-0.75 transition disabled:opacity-60"
              :disabled="isSaving"
              @click="toggle"
            >
              <span class="block h-5.5 w-5.5 rounded-full bg-white shadow" />
            </button>
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex gap-2">
            <button
              type="button"
              class="rounded-full px-4 py-1.5 text-sm font-medium transition"
              :class="tab === 'hours' ? 'bg-blue-600 text-white' : 'border border-slate-300 text-slate-600 hover:bg-slate-50'"
              @click="tab = 'hours'"
            >
              Intern hours
            </button>
            <button
              type="button"
              class="rounded-full px-4 py-1.5 text-sm font-medium transition"
              :class="tab === 'sites' ? 'bg-blue-600 text-white' : 'border border-slate-300 text-slate-600 hover:bg-slate-50'"
              @click="tab = 'sites'"
            >
              Clock-in sites ({{ sites.length }})
            </button>
          </div>

          <p v-if="needsAttention > 0" class="text-xs text-slate-500">
            {{ needsAttention }} intern(s) have a session their supervisor still needs to close or review.
          </p>
        </div>

        <!-- Hours -->
        <div v-if="tab === 'hours'" class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70">
          <table class="w-full table-fixed text-left text-sm">
            <colgroup>
              <col />
              <col class="w-44" />
              <col class="w-36" />
              <col class="w-40" />
              <col class="w-32" />
            </colgroup>
            <thead>
              <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-400">
                <th class="px-4 py-3">Intern</th>
                <th class="px-4 py-3">Company</th>
                <th class="px-4 py-3">Batch</th>
                <th class="px-4 py-3">Hours</th>
                <th class="px-4 py-3">Attention</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!rows.length">
                <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">
                  No interns have clocked in yet. Their supervisors create a clock-in site first, from their own Daily
                  Time Record page.
                </td>
              </tr>
              <tr v-for="row in rows" :key="row.student_id" class="border-b border-slate-100 last:border-0">
                <td class="truncate px-4 py-3 text-slate-700">
                  {{ row.student_name }}
                  <span class="block truncate text-xs text-slate-400">{{ row.student_id_number }}</span>
                </td>
                <td class="truncate px-4 py-3 text-slate-700">{{ row.company ?? '—' }}</td>
                <td class="truncate px-4 py-3 text-slate-700">{{ row.batch ?? '—' }}</td>
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2">
                    <div class="h-1.5 w-20 shrink-0 overflow-hidden rounded-full bg-slate-100">
                      <div
                        class="h-full rounded-full bg-blue-600"
                        :style="{ width: `${row.hours_percent ?? 0}%` }"
                      />
                    </div>
                    <span class="text-xs text-slate-600">
                      {{ row.hours_completed }}<template v-if="row.hours_required">/{{ row.hours_required }}</template>
                    </span>
                  </div>
                </td>
                <td class="px-4 py-3">
                  <span v-if="row.flagged_sessions > 0" class="mr-1 inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200/70">
                    {{ row.flagged_sessions }} flagged
                  </span>
                  <span v-if="row.open_sessions > 0" class="inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-blue-200/70">
                    {{ row.open_sessions }} open
                  </span>
                  <span v-if="!row.flagged_sessions && !row.open_sessions" class="text-xs text-slate-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Sites -->
        <div v-else>
          <div class="mb-3 flex items-start gap-2">
            <p class="text-sm text-slate-500">
              Where each company's QR code is anchored. Check these against the company's real address.
            </p>
            <TooltipWrap
              label="A supervisor who generated their QR code away from the workplace anchored the geofence to wherever they were standing. Nothing in the automated flow can detect that, so this list is the check."
            >
              <span
                class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500"
                aria-label="A supervisor who generated their QR code away from the workplace anchored the geofence to wherever they were standing. Nothing in the automated flow can detect that, so this list is the check."
              >
                i
              </span>
            </TooltipWrap>
          </div>

          <div v-if="!sites.length" class="rounded-xl bg-white p-6 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200/70">
            No clock-in sites have been created by your interns' supervisors yet.
          </div>

          <div v-else class="grid gap-4 md:grid-cols-2">
            <div
              v-for="site in sites"
              :key="site.id"
              class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70"
              :class="{ 'opacity-60': !site.is_active }"
            >
              <h3 class="text-sm font-semibold text-slate-900">{{ site.label }}</h3>
              <p class="text-xs text-slate-500">{{ site.company }} &middot; {{ site.radius_meters }}m radius</p>
              <p class="mt-2 text-xs text-slate-400">Registered address: {{ site.company_address ?? '—' }}</p>

              <p
                v-if="site.accuracy_is_poor"
                class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200/70"
              >
                Anchored on a vague location reading (±{{ site.captured_accuracy }}m). Interns may struggle to
                clock in here.
              </p>

              <a
                :href="site.map_url"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-3 inline-flex text-xs font-medium text-blue-600 hover:underline"
              >
                View {{ site.latitude.toFixed(5) }}, {{ site.longitude.toFixed(5) }} on a map
              </a>
            </div>
          </div>
        </div>
      </template>
    </LoadStatus>

    <ToastHost />
  </div>
</template>

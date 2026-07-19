<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { confirmAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import LoadStatus from '@/components/LoadStatus.vue'
import type { ArchivePurgeResult, PaginatedResponse, SystemSettingsMap, User, WeeklyBundlingResult } from '@/types/api'

const systemInfo = [
  ['System Version', 'v1.0.0 (Phase 2)'],
  ['Backend', 'Laravel 13'],
  ['Frontend', 'Vue.js 3'],
  ['Database', 'MySQL'],
  ['Institution', 'Mater Dei College'],
]

const generalForm = reactive<SystemSettingsMap>({
  system_name: '',
  institution_name: '',
  institution_address: '',
  system_email: '',
})

const isLoadingSettings = ref(true)
const isSavingSettings = ref(false)
const settingsError = ref('')
const saveError = ref('')
const settingsSaved = ref(false)

const loadSettings = async () => {
  isLoadingSettings.value = true
  settingsError.value = ''

  try {
    const response = await api.get<SystemSettingsMap>('/api/admin/system-settings')
    Object.assign(generalForm, {
      system_name: response.data.system_name ?? '',
      institution_name: response.data.institution_name ?? '',
      institution_address: response.data.institution_address ?? '',
      system_email: response.data.system_email ?? '',
    })
  } catch (error) {
    settingsError.value = categorizeError(error, 'Unable to load system settings.').message
  } finally {
    isLoadingSettings.value = false
  }
}

const saveSettings = async () => {
  isSavingSettings.value = true
  saveError.value = ''
  settingsSaved.value = false

  try {
    await api.put('/api/admin/system-settings', generalForm)
    settingsSaved.value = true
  } catch (error) {
    saveError.value = categorizeError(error, 'Unable to save settings.').message
  } finally {
    isSavingSettings.value = false
  }
}

const cancelSettings = () => {
  settingsSaved.value = false
  saveError.value = ''
  loadSettings()
}

const studentSearch = ref('')
const studentResults = ref<User[]>([])
const isSearchingStudents = ref(false)
const issuingForId = ref<number | null>(null)
const issueError = ref('')
const issuedPassword = ref<{ studentName: string; password: string } | null>(null)

let searchDebounce: ReturnType<typeof setTimeout> | undefined

const searchStudents = async () => {
  if (!studentSearch.value.trim()) {
    studentResults.value = []
    return
  }

  isSearchingStudents.value = true

  try {
    const response = await api.get<PaginatedResponse<User>>('/api/admin/users', {
      params: { role: 'student', search: studentSearch.value },
    })
    studentResults.value = response.data.data
  } catch {
    studentResults.value = []
  } finally {
    isSearchingStudents.value = false
  }
}

watch(studentSearch, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(searchStudents, 300)
})

const issueTemporaryPassword = async (student: User) => {
  if (!(await confirmAction(`Issue a temporary password for ${student.name}? Their current password will stop working immediately.`))) {
    return
  }

  issuingForId.value = student.id
  issueError.value = ''

  try {
    const response = await api.patch<{ temporary_password: string }>(`/api/admin/users/${student.id}/temporary-password`)
    issuedPassword.value = { studentName: student.name, password: response.data.temporary_password }
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    issueError.value = data?.message ?? 'Unable to issue a temporary password.'
  } finally {
    issuingForId.value = null
  }
}

// --- Weekly Bundling demo trigger --------------------------------------
const weeklyBundlingWeekStart = ref('')
const isRunningWeeklyBundling = ref(false)
const weeklyBundlingError = ref('')
const weeklyBundlingResult = ref<WeeklyBundlingResult | null>(null)

const runWeeklyBundlingNow = async () => {
  const weekLabel = weeklyBundlingWeekStart.value || 'the most recently completed Mon–Fri week'
  if (!(await confirmAction(`Run Weekly Bundling now for ${weekLabel}? This compiles Daily Accomplishment entries into each active student's Weekly Log narrative (drafts only — already-submitted logs are left untouched).`))) {
    return
  }

  isRunningWeeklyBundling.value = true
  weeklyBundlingError.value = ''

  try {
    const { data } = await api.post<WeeklyBundlingResult>('/api/admin/weekly-bundling/run', {
      week_start: weeklyBundlingWeekStart.value || undefined,
    })
    weeklyBundlingResult.value = data
    showToast(`Weekly Bundling complete: ${data.compiled} compiled, ${data.skipped_submitted} already submitted.`)
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    weeklyBundlingError.value = data?.message ?? 'Unable to run Weekly Bundling.'
  } finally {
    isRunningWeeklyBundling.value = false
  }
}

// --- Archive Purge demo trigger -----------------------------------------
const isRunningPurge = ref(false)
const purgeError = ref('')
const purgeResult = ref<ArchivePurgeResult | null>(null)
const purgeAsOf = ref('')

const runPurgeNow = async () => {
  if (!(await confirmAction('Run the archive purge now? This permanently deletes every batch roster record archived 30+ days ago (unless purging it would re-gate a legacy student). This cannot be undone.'))) {
    return
  }

  isRunningPurge.value = true
  purgeError.value = ''

  try {
    const { data } = await api.post<ArchivePurgeResult>('/api/admin/roster/purge-archived/run', {
      now: purgeAsOf.value || undefined,
    })
    purgeResult.value = data
    showToast(`Archive purge complete: ${data.purged} record${data.purged === 1 ? '' : 's'} purged, ${data.protected} protected.`)
  } catch (error) {
    purgeError.value = categorizeError(error, 'Unable to run the archive purge.').message
  } finally {
    isRunningPurge.value = false
  }
}

onMounted(loadSettings)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />
    <div class="grid gap-5 xl:grid-cols-2">
      <div class="space-y-5">
        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">General Settings</h2>
          <LoadStatus class="mt-4" :loading="isLoadingSettings" :error="settingsError" :retry="loadSettings">
          <div class="mt-1 space-y-4">
            <label class="block">
              <span class="text-xs font-bold text-slate-600">System Name</span>
              <input v-model="generalForm.system_name" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Institution Name</span>
              <input v-model="generalForm.institution_name" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Institution Address</span>
              <input v-model="generalForm.institution_address" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">System Email</span>
              <input v-model="generalForm.system_email" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" type="email" />
            </label>
          </div>
          </LoadStatus>
        </div>

        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Password Management</h2>
          <p class="mt-1 text-xs text-slate-500">
            Search for a student and issue a temporary password if they're unable to sign in. They'll be required to set a
            new password the next time they log in.
          </p>

          <input
            v-model="studentSearch"
            class="mt-4 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            placeholder="Search student by name..."
          />

          <p v-if="isSearchingStudents" class="mt-3 text-sm text-slate-500">Searching...</p>

          <ul v-else-if="studentResults.length > 0" class="mt-3 divide-y divide-slate-100">
            <li v-for="student in studentResults" :key="student.id" class="flex items-center justify-between py-2">
              <div>
                <p class="text-sm font-semibold text-slate-900">{{ student.name }}</p>
                <p class="text-xs text-slate-500">{{ student.email }}</p>
              </div>
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
                :disabled="issuingForId === student.id"
                @click="issueTemporaryPassword(student)"
              >
                {{ issuingForId === student.id ? 'Issuing...' : 'Issue Temporary Password' }}
              </button>
            </li>
          </ul>
          <p v-else-if="studentSearch.trim()" class="mt-3 text-sm text-slate-400">No students found.</p>

          <p v-if="issueError" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ issueError }}</p>

          <div v-if="issuedPassword" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">Temporary password for {{ issuedPassword.studentName }}:</p>
            <p class="mt-1 font-mono text-base">{{ issuedPassword.password }}</p>
            <p class="mt-2 text-xs">Share this with the student securely. It will not be shown again.</p>
            <button type="button" class="mt-2 text-xs font-semibold underline" @click="issuedPassword = null">Dismiss</button>
          </div>
        </div>
      </div>

      <div class="space-y-5">
        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Journal Settings</h2>
          <div class="mt-5 space-y-4">
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Weekly Compilation Day</span>
              <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                <option>Sunday</option>
                <option>Saturday</option>
                <option>Monday</option>
              </select>
              <p class="mt-1 text-xs text-slate-500">
                Weekly journals are automatically compiled and forwarded to company supervisors on this day.
              </p>
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Minimum Word Count</span>
              <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" type="number" value="250" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Submission Deadline</span>
              <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                <option>11:59 PM (Same Day)</option>
                <option>9:00 AM (Next Day)</option>
              </select>
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" />
              Exclude late/overdue entries
            </label>
          </div>
        </div>

        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Weekly Bundling</h2>
          <p class="mt-1 text-xs text-slate-500">
            Compiles each active student's submitted Daily Accomplishment entries (Mon–Fri) into their Weekly Log narrative.
            Runs automatically every Saturday at 00:00 for the week that just ended — use this to trigger it on demand.
          </p>

          <label class="mt-4 block">
            <span class="text-xs font-bold text-slate-600">Week (optional)</span>
            <input v-model="weeklyBundlingWeekStart" type="date" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            <p class="mt-1 text-xs text-slate-400">Any date within the target week. Leave blank for the most recently completed Mon–Fri.</p>
          </label>

          <button
            type="button"
            class="mt-3 rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isRunningWeeklyBundling"
            @click="runWeeklyBundlingNow"
          >
            {{ isRunningWeeklyBundling ? 'Running...' : 'Run Weekly Bundling Now' }}
          </button>

          <p v-if="weeklyBundlingError" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ weeklyBundlingError }}</p>

          <div v-if="weeklyBundlingResult" class="mt-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
            <p class="font-semibold">{{ weeklyBundlingResult.week_start }} to {{ weeklyBundlingResult.week_end }}</p>
            <p class="mt-1 text-xs">
              {{ weeklyBundlingResult.compiled }} weekly log{{ weeklyBundlingResult.compiled === 1 ? '' : 's' }} compiled ·
              {{ weeklyBundlingResult.skipped_submitted }} already submitted (untouched)
            </p>
          </div>
        </div>

        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Archive Purge</h2>
          <p class="mt-1 text-xs text-slate-500">
            Permanently deletes batch roster records that coordinators archived 30+ days ago —
            unless deleting one would re-gate an already-graduated legacy student with no info sheet on file, in
            which case it's skipped and re-checked next run. Runs automatically every night at 02:00 — use this to
            trigger it on demand.
          </p>

          <label class="mt-4 block">
            <span class="text-xs font-bold text-slate-600">As of (optional)</span>
            <input v-model="purgeAsOf" type="date" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            <p class="mt-1 text-xs text-slate-400">Treat this date as "now" for the 30-day cutoff. Leave blank to use the real current time.</p>
          </label>

          <button
            type="button"
            class="mt-3 rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isRunningPurge"
            @click="runPurgeNow"
          >
            {{ isRunningPurge ? 'Running...' : 'Run Purge Now' }}
          </button>

          <p v-if="purgeError" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ purgeError }}</p>

          <div v-if="purgeResult" class="mt-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
            <p class="font-semibold">
              {{ purgeResult.purged }} record{{ purgeResult.purged === 1 ? '' : 's' }} purged ·
              {{ purgeResult.protected }} protected
            </p>
            <p class="mt-1 text-xs">Cutoff: records archived before {{ purgeResult.cutoff }}.</p>
          </div>
        </div>

        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">System Information</h2>
          <div class="mt-4 divide-y divide-slate-100">
            <div v-for="[label, value] in systemInfo" :key="label" class="flex items-center justify-between py-2">
              <span class="text-sm text-slate-500">{{ label }}</span>
              <span class="text-sm font-semibold text-slate-800">{{ value }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <p v-if="saveError" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ saveError }}</p>
    <p v-if="settingsSaved" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">Settings saved.</p>

    <div class="flex justify-end gap-3">
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" @click="cancelSettings">
        Cancel
      </button>
      <button
        type="button"
        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:grayscale disabled:cursor-not-allowed"
        :disabled="isSavingSettings"
        @click="saveSettings"
      >
        {{ isSavingSettings ? 'Saving...' : 'Save Changes' }}
      </button>
    </div>
  </section>
</template>

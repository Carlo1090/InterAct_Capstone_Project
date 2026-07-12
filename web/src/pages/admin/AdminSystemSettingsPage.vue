<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { confirmAction } from '@/lib/toast'
import type { PaginatedResponse, SystemSettingsMap, User } from '@/types/api'

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
  } catch {
    settingsError.value = 'Unable to load system settings.'
  } finally {
    isLoadingSettings.value = false
  }
}

const saveSettings = async () => {
  isSavingSettings.value = true
  settingsError.value = ''
  settingsSaved.value = false

  try {
    await api.put('/api/admin/system-settings', generalForm)
    settingsSaved.value = true
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    settingsError.value = data?.message ?? 'Unable to save settings.'
  } finally {
    isSavingSettings.value = false
  }
}

const cancelSettings = () => {
  settingsSaved.value = false
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
  if (!confirmAction(`Issue a temporary password for ${student.name}? Their current password will stop working immediately.`)) {
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

onMounted(loadSettings)
</script>

<template>
  <section class="space-y-5">
    <div class="grid gap-5 xl:grid-cols-2">
      <div class="space-y-5">
        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">General Settings</h2>
          <p v-if="isLoadingSettings" class="mt-4 text-sm text-slate-500">Loading...</p>
          <div v-else class="mt-5 space-y-4">
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
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 disabled:opacity-50"
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

    <p v-if="settingsError" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ settingsError }}</p>
    <p v-if="settingsSaved" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">Settings saved.</p>

    <div class="flex justify-end gap-3">
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" @click="cancelSettings">
        Cancel
      </button>
      <button
        type="button"
        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50"
        :disabled="isSavingSettings"
        @click="saveSettings"
      >
        {{ isSavingSettings ? 'Saving...' : 'Save Changes' }}
      </button>
    </div>
  </section>
</template>

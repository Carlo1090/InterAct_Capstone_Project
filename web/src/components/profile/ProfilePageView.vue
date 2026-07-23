<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { useAuthStore } from '@/stores/auth'
import { showToast } from '@/lib/toast'
import AvatarCropperModal from '@/components/profile/AvatarCropperModal.vue'
import type { ProfileActivityLog } from '@/types/api'

type Tab = 'profile' | 'password' | 'activity'

const auth = useAuthStore()

const activeTab = ref<Tab>('profile')

const userName = computed(() => auth.user?.name ?? '')
const initials = computed(() =>
  userName.value
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

const errorMessageFrom = (error: unknown, fallback: string): string => {
  const data = axios.isAxiosError(error) ? error.response?.data : null
  return data?.message ?? fallback
}

// Profile info
const profileForm = reactive({
  name: auth.user?.name ?? '',
  username: auth.user?.username ?? '',
  email: auth.user?.email ?? '',
})
const isSavingProfile = ref(false)
const profileError = ref('')

const saveProfile = async () => {
  isSavingProfile.value = true
  profileError.value = ''

  try {
    await api.put('/api/profile', profileForm)
    await auth.fetchUser()
    showToast('Profile updated.')
  } catch (error) {
    profileError.value = errorMessageFrom(error, 'Unable to update profile. Please check the fields and try again.')
  } finally {
    isSavingProfile.value = false
  }
}

// Avatar
const MAX_PHOTO_BYTES = 2 * 1024 * 1024
const ALLOWED_PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp']

const fileInput = ref<HTMLInputElement | null>(null)
const isUploadingPhoto = ref(false)
const photoError = ref('')
const croppingFile = ref<File | null>(null)

const triggerFileSelect = () => fileInput.value?.click()

const resetFileInput = () => {
  if (fileInput.value) fileInput.value.value = ''
}

const onPhotoSelected = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  photoError.value = ''

  if (!ALLOWED_PHOTO_TYPES.includes(file.type)) {
    photoError.value = 'Invalid file type. Please choose a JPG, PNG, or WEBP image.'
    resetFileInput()
    return
  }

  if (file.size > MAX_PHOTO_BYTES) {
    photoError.value = 'File too large. Please choose an image under 2MB.'
    resetFileInput()
    return
  }

  // Opens the crop modal for a live preview + adjustment; the actual upload
  // only fires once the user confirms the crop (onCropConfirmed below).
  croppingFile.value = file
}

const onCropCancelled = () => {
  croppingFile.value = null
  resetFileInput()
}

const onCropConfirmed = async (blob: Blob) => {
  croppingFile.value = null
  isUploadingPhoto.value = true
  photoError.value = ''

  const formData = new FormData()
  formData.append('photo', blob, 'avatar.png')

  try {
    await api.post('/api/profile/photo', formData)
    await auth.fetchUser()
    showToast('Profile photo updated.')
  } catch (error) {
    photoError.value = errorMessageFrom(error, 'Unable to upload photo. Use a JPG, PNG, or WEBP under 2MB.')
  } finally {
    isUploadingPhoto.value = false
    resetFileInput()
  }
}

const removePhoto = async () => {
  isUploadingPhoto.value = true
  photoError.value = ''

  try {
    await api.delete('/api/profile/photo')
    await auth.fetchUser()
    showToast('Profile photo removed.')
  } catch (error) {
    photoError.value = errorMessageFrom(error, 'Unable to remove photo.')
  } finally {
    isUploadingPhoto.value = false
  }
}

// Change password
const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const isSavingPassword = ref(false)
const passwordError = ref('')
const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)

// Coaches toward length/entropy rather than composition rules (no forced
// symbols/uppercase) — mirrors the backend's Password::defaults(), which is
// just min:8 with nothing else required. Character-set variety only ever
// adds to the score, never gates it, so a long passphrase without symbols
// still scores well.
const STRENGTH_LABELS = ['Too short', 'Weak', 'Fair', 'Good', 'Strong']

const scorePasswordStrength = (password: string): { score: number; label: string } => {
  if (!password) return { score: 0, label: 'Enter a password' }

  const length = password.length
  let score = 0
  if (length >= 20) score = 4
  else if (length >= 16) score = 3
  else if (length >= 12) score = 2
  else if (length >= 8) score = 1

  let categories = 0
  if (/[a-z]/.test(password)) categories++
  if (/[A-Z]/.test(password)) categories++
  if (/[0-9]/.test(password)) categories++
  if (/[^a-zA-Z0-9]/.test(password)) categories++

  if (length >= 8 && categories >= 3 && score < 4) score++

  return { score, label: STRENGTH_LABELS[score] }
}

const passwordStrength = computed(() => scorePasswordStrength(passwordForm.password))

const passwordChecklist = computed(() => ({
  meetsMinimum: passwordForm.password.length >= 8,
  meetsRecommended: passwordForm.password.length >= 12,
  matchesConfirmation:
    passwordForm.password.length > 0 &&
    passwordForm.password_confirmation.length > 0 &&
    passwordForm.password === passwordForm.password_confirmation,
  differsFromCurrent:
    passwordForm.password.length > 0 &&
    passwordForm.current_password.length > 0 &&
    passwordForm.password !== passwordForm.current_password,
}))

const savePassword = async () => {
  isSavingPassword.value = true
  passwordError.value = ''

  try {
    await api.put('/api/profile/password', passwordForm)
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
    await auth.fetchUser()
    showToast('Password updated.')
  } catch (error) {
    passwordError.value = errorMessageFrom(error, 'Unable to update password. Please check the fields and try again.')
  } finally {
    isSavingPassword.value = false
  }
}

// Activity log
const activityLogs = ref<ProfileActivityLog[]>([])
const isLoadingActivity = ref(false)
const activityError = ref('')
const hasLoadedActivity = ref(false)

const loadActivity = async () => {
  isLoadingActivity.value = true
  activityError.value = ''

  try {
    const response = await api.get('/api/profile/activity')
    activityLogs.value = response.data.data
    hasLoadedActivity.value = true
  } catch {
    activityError.value = 'Unable to load your activity log.'
  } finally {
    isLoadingActivity.value = false
  }
}

const selectTab = (tab: Tab) => {
  activeTab.value = tab
  if (tab === 'activity' && !hasLoadedActivity.value) {
    loadActivity()
  }
}

onMounted(() => {
  if (auth.user?.must_change_password) {
    activeTab.value = 'password'
  }
})
</script>

<template>
  <section class="max-w-2xl space-y-5">
    <div
      v-if="auth.user?.must_change_password"
      class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
    >
      A temporary password was issued for your account. Please set a new password before continuing.
    </div>

    <div class="flex gap-1 rounded-lg bg-slate-100 p-1">
      <button
        type="button"
        class="flex-1 rounded-md px-4 py-2 text-sm font-semibold transition"
        :class="activeTab === 'profile' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
        @click="selectTab('profile')"
      >
        Profile
      </button>
      <button
        type="button"
        class="flex-1 rounded-md px-4 py-2 text-sm font-semibold transition"
        :class="activeTab === 'password' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
        @click="selectTab('password')"
      >
        Change Password
      </button>
      <button
        type="button"
        class="flex-1 rounded-md px-4 py-2 text-sm font-semibold transition"
        :class="activeTab === 'activity' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
        @click="selectTab('activity')"
      >
        Activity Log
      </button>
    </div>

    <div v-if="activeTab === 'profile'" class="space-y-5 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center gap-5">
        <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-2xl font-bold text-white">
          <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="Profile photo" class="h-full w-full object-cover" />
          <span v-else>{{ initials }}</span>
        </div>
        <div class="space-y-2">
          <div class="flex gap-2">
            <button
              type="button"
              class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
              :disabled="isUploadingPhoto"
              @click="triggerFileSelect"
            >
              <svg
                v-if="isUploadingPhoto"
                class="h-4 w-4 animate-spin text-slate-400"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
              >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
              </svg>
              {{ isUploadingPhoto ? 'Uploading...' : 'Change Photo' }}
            </button>
            <button
              v-if="auth.user?.avatar_url"
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
              :disabled="isUploadingPhoto"
              @click="removePhoto"
            >
              Remove
            </button>
          </div>
          <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onPhotoSelected" />
          <p class="text-xs text-slate-400">JPG, PNG, or WEBP. Max 2MB.</p>
          <p v-if="photoError" class="text-xs text-red-600">{{ photoError }}</p>
        </div>
      </div>

      <form class="space-y-5" @submit.prevent="saveProfile">
        <div>
          <label class="mb-2 block text-sm font-medium text-slate-700" for="profile-name">Full Name</label>
          <input
            id="profile-name"
            v-model="profileForm.name"
            type="text"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-slate-700" for="profile-username">Username</label>
          <input
            id="profile-username"
            v-model="profileForm.username"
            type="text"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-slate-700" for="profile-email">Email</label>
          <input
            id="profile-email"
            v-model="profileForm.email"
            type="email"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
        </div>

        <p v-if="profileError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ profileError }}</p>

        <button
          type="submit"
          class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
          :disabled="isSavingProfile"
        >
          {{ isSavingProfile ? 'Saving...' : 'Save Profile' }}
        </button>
      </form>
    </div>

    <form
      v-else-if="activeTab === 'password'"
      class="space-y-5 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200"
      @submit.prevent="savePassword"
    >
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="current-password">Current Password</label>
        <div class="relative">
          <input
            id="current-password"
            v-model="passwordForm.current_password"
            :type="showCurrentPassword ? 'text' : 'password'"
            autocomplete="current-password"
            class="w-full rounded-md border border-slate-300 px-3 py-2 pr-10 text-sm"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 hover:text-slate-600"
            :aria-label="showCurrentPassword ? 'Hide password' : 'Show password'"
            @click="showCurrentPassword = !showCurrentPassword"
          >
            <svg v-if="showCurrentPassword" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
              <path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M6.6 6.6C4.5 8 3 10 2.5 12c1.4 3.6 5 7 9.5 7 1.6 0 3.1-.4 4.4-1.1M9.9 4.2A10.4 10.4 0 0112 4c4.5 0 8.1 3.4 9.5 7-.5 1.2-1.2 2.4-2.1 3.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
              <path d="M2.5 12C3.9 8.4 7.5 5 12 5s8.1 3.4 9.5 7c-1.4 3.6-5 7-9.5 7s-8.1-3.4-9.5-7z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
              <circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.6" />
            </svg>
          </button>
        </div>
      </div>

      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="new-password">New Password</label>
        <div class="relative">
          <input
            id="new-password"
            v-model="passwordForm.password"
            :type="showNewPassword ? 'text' : 'password'"
            autocomplete="new-password"
            class="w-full rounded-md border border-slate-300 px-3 py-2 pr-10 text-sm"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 hover:text-slate-600"
            :aria-label="showNewPassword ? 'Hide password' : 'Show password'"
            @click="showNewPassword = !showNewPassword"
          >
            <svg v-if="showNewPassword" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
              <path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M6.6 6.6C4.5 8 3 10 2.5 12c1.4 3.6 5 7 9.5 7 1.6 0 3.1-.4 4.4-1.1M9.9 4.2A10.4 10.4 0 0112 4c4.5 0 8.1 3.4 9.5 7-.5 1.2-1.2 2.4-2.1 3.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
              <path d="M2.5 12C3.9 8.4 7.5 5 12 5s8.1 3.4 9.5 7c-1.4 3.6-5 7-9.5 7s-8.1-3.4-9.5-7z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
              <circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.6" />
            </svg>
          </button>
        </div>

        <div v-if="passwordForm.password" class="mt-2 space-y-1">
          <div class="flex h-1.5 gap-1 overflow-hidden rounded-full bg-slate-100">
            <span
              v-for="segment in 4"
              :key="segment"
              class="flex-1 rounded-full transition-colors"
              :class="segment <= passwordStrength.score
                ? [
                    'bg-rose-400',
                    'bg-rose-400',
                    'bg-amber-400',
                    'bg-emerald-500',
                    'bg-emerald-600',
                  ][passwordStrength.score]
                : 'bg-slate-100'"
            />
          </div>
          <p
            class="text-xs font-medium"
            :class="{
              'text-rose-600': passwordStrength.score <= 1,
              'text-amber-600': passwordStrength.score === 2,
              'text-emerald-600': passwordStrength.score >= 3,
            }"
          >
            {{ passwordStrength.label }}
          </p>
        </div>
      </div>

      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="new-password-confirmation">Confirm New Password</label>
        <div class="relative">
          <input
            id="new-password-confirmation"
            v-model="passwordForm.password_confirmation"
            :type="showConfirmPassword ? 'text' : 'password'"
            autocomplete="new-password"
            class="w-full rounded-md border border-slate-300 px-3 py-2 pr-10 text-sm"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 hover:text-slate-600"
            :aria-label="showConfirmPassword ? 'Hide password' : 'Show password'"
            @click="showConfirmPassword = !showConfirmPassword"
          >
            <svg v-if="showConfirmPassword" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
              <path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M6.6 6.6C4.5 8 3 10 2.5 12c1.4 3.6 5 7 9.5 7 1.6 0 3.1-.4 4.4-1.1M9.9 4.2A10.4 10.4 0 0112 4c4.5 0 8.1 3.4 9.5 7-.5 1.2-1.2 2.4-2.1 3.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
              <path d="M2.5 12C3.9 8.4 7.5 5 12 5s8.1 3.4 9.5 7c-1.4 3.6-5 7-9.5 7s-8.1-3.4-9.5-7z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
              <circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.6" />
            </svg>
          </button>
        </div>
      </div>

      <div class="space-y-1.5 rounded-md bg-slate-50 px-3 py-2.5 text-xs">
        <p class="flex items-center gap-1.5" :class="passwordChecklist.meetsMinimum ? 'text-emerald-700' : 'text-slate-500'">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-3.5 w-3.5 shrink-0">
            <circle v-if="!passwordChecklist.meetsMinimum" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
            <path v-else d="M4 12l5 5L20 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          At least 8 characters
        </p>
        <p class="flex items-center gap-1.5" :class="passwordChecklist.meetsRecommended ? 'text-emerald-700' : 'text-slate-500'">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-3.5 w-3.5 shrink-0">
            <circle v-if="!passwordChecklist.meetsRecommended" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
            <path v-else d="M4 12l5 5L20 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          12+ characters (recommended)
        </p>
        <p class="flex items-center gap-1.5" :class="passwordChecklist.differsFromCurrent ? 'text-emerald-700' : 'text-slate-500'">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-3.5 w-3.5 shrink-0">
            <circle v-if="!passwordChecklist.differsFromCurrent" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
            <path v-else d="M4 12l5 5L20 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          Different from current password
        </p>
        <p class="flex items-center gap-1.5" :class="passwordChecklist.matchesConfirmation ? 'text-emerald-700' : 'text-slate-500'">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-3.5 w-3.5 shrink-0">
            <circle v-if="!passwordChecklist.matchesConfirmation" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
            <path v-else d="M4 12l5 5L20 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          Passwords match
        </p>
      </div>

      <p v-if="passwordError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ passwordError }}</p>

      <button
        type="submit"
        class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
        :disabled="isSavingPassword"
      >
        {{ isSavingPassword ? 'Saving...' : 'Update Password' }}
      </button>
    </form>

    <div v-else class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <p v-if="isLoadingActivity" class="px-6 py-6 text-sm text-slate-500">Loading...</p>
      <p v-else-if="activityError" class="px-6 py-6 text-sm text-red-700">{{ activityError }}</p>
      <table v-else class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Timestamp</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Details</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="activityLogs.length === 0">
            <td colspan="3" class="px-4 py-6 text-center text-sm text-slate-500">No activity recorded yet.</td>
          </tr>
          <tr v-for="log in activityLogs" :key="log.id">
            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ log.logged_at }}</td>
            <td class="px-4 py-3 text-sm font-semibold text-slate-700">{{ log.action }}</td>
            <td class="max-w-sm px-4 py-3 text-sm text-slate-500">{{ log.description }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <AvatarCropperModal
      v-if="croppingFile"
      :image-file="croppingFile"
      @cancel="onCropCancelled"
      @cropped="onCropConfirmed"
    />
  </section>
</template>

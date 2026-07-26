<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { useAuthStore } from '@/stores/auth'
import { showToast } from '@/lib/toast'

const props = defineProps<{ forced?: boolean }>()
const emit = defineEmits<{ success: [] }>()

const auth = useAuthStore()

const errorMessageFrom = (error: unknown, fallback: string): string => {
  const data = axios.isAxiosError(error) ? error.response?.data : null
  return data?.message ?? fallback
}

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
    emit('success')
  } catch (error) {
    passwordError.value = errorMessageFrom(error, 'Unable to update password. Please check the fields and try again.')
  } finally {
    isSavingPassword.value = false
  }
}
</script>

<template>
  <form class="space-y-4 p-4" @submit.prevent="savePassword">
    <div v-if="props.forced" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
      A temporary password was issued for your account. Please set a new password before continuing.
    </div>

    <div>
      <label class="mb-1.5 block text-xs font-medium text-slate-700" for="popover-current-password">Current Password</label>
      <div class="relative">
        <input
          id="popover-current-password"
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
      <label class="mb-1.5 block text-xs font-medium text-slate-700" for="popover-new-password">New Password</label>
      <div class="relative">
        <input
          id="popover-new-password"
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
      <label class="mb-1.5 block text-xs font-medium text-slate-700" for="popover-new-password-confirmation">Confirm New Password</label>
      <div class="relative">
        <input
          id="popover-new-password-confirmation"
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

    <p v-if="passwordError" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">{{ passwordError }}</p>

    <button
      type="submit"
      class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
      :disabled="isSavingPassword"
    >
      {{ isSavingPassword ? 'Saving...' : 'Update Password' }}
    </button>
  </form>
</template>

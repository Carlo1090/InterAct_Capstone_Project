<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { useAuthStore } from '@/stores/auth'
import { showToast } from '@/lib/toast'
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
const fileInput = ref<HTMLInputElement | null>(null)
const isUploadingPhoto = ref(false)
const photoError = ref('')

const triggerFileSelect = () => fileInput.value?.click()

const onPhotoSelected = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  isUploadingPhoto.value = true
  photoError.value = ''

  const formData = new FormData()
  formData.append('photo', file)

  try {
    await api.post('/api/profile/photo', formData)
    await auth.fetchUser()
    showToast('Profile photo updated.')
  } catch (error) {
    photoError.value = errorMessageFrom(error, 'Unable to upload photo. Use a JPG, PNG, or WEBP under 2MB.')
  } finally {
    isUploadingPhoto.value = false
    input.value = ''
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
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 disabled:opacity-50"
              :disabled="isUploadingPhoto"
              @click="triggerFileSelect"
            >
              {{ isUploadingPhoto ? 'Uploading...' : 'Change Photo' }}
            </button>
            <button
              v-if="auth.user?.avatar_url"
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 disabled:opacity-50"
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
          class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
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
        <input
          id="current-password"
          v-model="passwordForm.current_password"
          type="password"
          autocomplete="current-password"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="new-password">New Password</label>
        <input
          id="new-password"
          v-model="passwordForm.password"
          type="password"
          autocomplete="new-password"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="new-password-confirmation">Confirm New Password</label>
        <input
          id="new-password-confirmation"
          v-model="passwordForm.password_confirmation"
          type="password"
          autocomplete="new-password"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <p v-if="passwordError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ passwordError }}</p>

      <button
        type="submit"
        class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
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
  </section>
</template>

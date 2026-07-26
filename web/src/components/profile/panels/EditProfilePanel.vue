<script setup lang="ts">
import { reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { useAuthStore } from '@/stores/auth'
import { showToast } from '@/lib/toast'
import AvatarCropperModal from '@/components/profile/AvatarCropperModal.vue'

const auth = useAuthStore()

const errorMessageFrom = (error: unknown, fallback: string): string => {
  const data = axios.isAxiosError(error) ? error.response?.data : null
  return data?.message ?? fallback
}

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
</script>

<template>
  <div class="space-y-5 p-4">
    <div class="flex items-center gap-4">
      <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-xl font-bold text-white">
        <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="Profile photo" class="h-full w-full object-cover" />
        <span v-else>{{ auth.user?.name?.split(' ').map((p) => p[0]).join('').slice(0, 2).toUpperCase() }}</span>
      </div>
      <div class="space-y-2">
        <div class="flex gap-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
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
            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
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

    <form class="space-y-4" @submit.prevent="saveProfile">
      <div>
        <label class="mb-1.5 block text-xs font-medium text-slate-700" for="popover-profile-name">Full Name</label>
        <input
          id="popover-profile-name"
          v-model="profileForm.name"
          type="text"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <div>
        <label class="mb-1.5 block text-xs font-medium text-slate-700" for="popover-profile-username">Username</label>
        <input
          id="popover-profile-username"
          v-model="profileForm.username"
          type="text"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <div>
        <label class="mb-1.5 block text-xs font-medium text-slate-700" for="popover-profile-email">Email</label>
        <input
          id="popover-profile-email"
          v-model="profileForm.email"
          type="email"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <p v-if="profileError" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">{{ profileError }}</p>

      <button
        type="submit"
        class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
        :disabled="isSavingProfile"
      >
        {{ isSavingProfile ? 'Saving...' : 'Save Profile' }}
      </button>
    </form>

    <AvatarCropperModal
      v-if="croppingFile"
      :image-file="croppingFile"
      @cancel="onCropCancelled"
      @cropped="onCropConfirmed"
    />
  </div>
</template>

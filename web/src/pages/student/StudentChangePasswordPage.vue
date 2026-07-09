<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import api from '@/lib/axios'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const isSaving = ref(false)
const errorMessage = ref('')

const submit = async () => {
  isSaving.value = true
  errorMessage.value = ''

  try {
    await api.put('/api/student/password', form)
    form.current_password = ''
    form.password = ''
    form.password_confirmation = ''
    await auth.fetchUser()
    router.push('/student/dashboard')
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    errorMessage.value = data?.message ?? 'Unable to update password. Please check the fields and try again.'
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <section class="max-w-md space-y-5">
    <div
      v-if="auth.user?.must_change_password"
      class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
    >
      A temporary password was issued for your account. Please set a new password before continuing.
    </div>

    <form class="space-y-5 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200" @submit.prevent="submit">
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="current-password">Current Password</label>
        <input
          id="current-password"
          v-model="form.current_password"
          type="password"
          autocomplete="current-password"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="new-password">New Password</label>
        <input
          id="new-password"
          v-model="form.password"
          type="password"
          autocomplete="new-password"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="new-password-confirmation">Confirm New Password</label>
        <input
          id="new-password-confirmation"
          v-model="form.password_confirmation"
          type="password"
          autocomplete="new-password"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>

      <button
        type="submit"
        class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
        :disabled="isSaving"
      >
        {{ isSaving ? 'Saving...' : 'Update Password' }}
      </button>
    </form>
  </section>
</template>

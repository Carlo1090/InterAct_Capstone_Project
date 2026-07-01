<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { roleRedirect } from '@/router'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const errorMessage = ref('')
const isLoading = ref(false)

const login = async () => {
  errorMessage.value = ''
  isLoading.value = true

  try {
    await auth.login(email.value, password.value)
    router.push(roleRedirect(auth.role))
  } catch {
    errorMessage.value = 'Invalid credentials. Please try again.'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
    <section class="w-full max-w-md rounded-lg bg-white p-8 shadow-sm ring-1 ring-slate-200">
      <div class="mb-8 text-center">
        <h1 class="text-3xl font-bold text-slate-950">InternTrack</h1>
        <p class="mt-2 text-sm text-slate-500">Internship Journal and Progress Monitoring</p>
      </div>

      <div class="space-y-5">
        <div>
          <label class="mb-2 block text-sm font-medium text-slate-700" for="email">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-2 focus:ring-slate-200"
            autocomplete="email"
          />
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-slate-700" for="password">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-2 focus:ring-slate-200"
            autocomplete="current-password"
            @keyup.enter="login"
          />
        </div>

        <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
          {{ errorMessage }}
        </p>

        <button
          type="button"
          class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
          :disabled="isLoading"
          @click="login"
        >
          {{ isLoading ? 'Logging in...' : 'Login' }}
        </button>
      </div>
    </section>
  </main>
</template>

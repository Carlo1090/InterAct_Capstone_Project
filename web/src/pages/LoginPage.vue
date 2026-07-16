<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { roleRedirect } from '@/router/index.ts'

const auth = useAuthStore()
const router = useRouter()

const identifier = ref('')
const password = ref('')
const errorMessage = ref('')
const isLoading = ref(false)

const login = async () => {
  errorMessage.value = ''
  isLoading.value = true

  try {
    await auth.login(identifier.value, password.value)
    router.push(roleRedirect(auth.role))
  } catch {
    errorMessage.value = 'Invalid credentials. Please try again.'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen w-full bg-slate-50">
    <!-- Branding panel -->
    <section
      class="relative hidden w-[42%] shrink-0 flex-col justify-between overflow-hidden bg-linear-to-br from-blue-900 via-blue-800 to-teal-500 px-12 py-16 text-white md:flex"
    >
      <div />

      <div class="text-center">
        <div class="mx-auto mb-8 flex h-44 w-44 items-center justify-center rounded-full bg-white shadow-lg">
          <img src="/images/mdc-logo.png" alt="Mater Dei College seal" class="h-36 w-36 rounded-full object-contain" />
        </div>
        <h1 class="text-4xl font-bold">Welcome to InternTrack</h1>
        <p class="mx-auto mt-4 max-w-sm text-sm text-blue-100">
          Internship Journal and Progress Monitoring System — Mater Dei College, Tubigon, Bohol.
        </p>
      </div>

      <p class="text-xs text-blue-200">&copy; Mater Dei College &middot; SIPP OJT Monitoring</p>

      <!-- Wave divider -->
      <svg
        class="pointer-events-none absolute inset-y-0 -right-1 h-full w-24"
        viewBox="0 0 100 800"
        preserveAspectRatio="none"
        aria-hidden="true"
      >
        <path
          d="M100,0 C40,120 100,200 60,320 C20,440 90,480 55,600 C25,700 90,740 100,800 L100,800 L100,0 Z"
          class="fill-slate-50"
        />
      </svg>
    </section>

    <!-- Form panel -->
    <section class="flex flex-1 items-center justify-center px-6 py-12">
      <div class="w-full max-w-sm">
        <div class="mb-4 flex justify-center md:hidden">
          <img src="/images/mdc-logo.png" alt="Mater Dei College seal" class="h-24 w-24 rounded-full object-contain" />
        </div>

        <h2 class="mb-8 text-3xl font-bold text-slate-900">Welcome Back!</h2>

        <div class="space-y-5">
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="identifier">Username</label>
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-teal-500 text-white">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                  <path
                    d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2.25c-3.75 0-7.5 1.875-7.5 5.625v1.125h15v-1.125c0-3.75-3.75-5.625-7.5-5.625Z"
                    fill="currentColor"
                  />
                </svg>
              </span>
              <input
                id="identifier"
                v-model="identifier"
                type="text"
                placeholder="Enter your username"
                class="w-full border-b-2 border-slate-200 bg-transparent px-1 py-2 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500"
                autocomplete="username"
              />
            </div>
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="password">Password</label>
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-500 text-white">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                  <path
                    d="M12 2a4 4 0 0 0-4 4v3H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a4 4 0 0 0-4-4Zm0 2a2 2 0 0 1 2 2v3h-4V6a2 2 0 0 1 2-2Z"
                    fill="currentColor"
                  />
                </svg>
              </span>
              <input
                id="password"
                v-model="password"
                type="password"
                placeholder="Enter your password"
                class="w-full border-b-2 border-slate-200 bg-transparent px-1 py-2 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500"
                autocomplete="current-password"
                @keyup.enter="login"
              />
            </div>
          </div>

          <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errorMessage }}
          </p>

          <button
            type="button"
            class="w-full rounded-full bg-linear-to-r from-blue-900 to-teal-500 px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="isLoading"
            @click="login"
          >
            {{ isLoading ? 'Logging in...' : 'Login' }}
          </button>

          <div class="rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-800">
            Demo logins (password: <strong>password</strong>): <strong>mdcadmin</strong>,
            <strong>mdccore</strong>, <strong>mdcbalbero</strong>, <strong>mdcstudent</strong>,
            <strong>mdcsupervisor</strong>
          </div>
        </div>
      </div>
    </section>
  </main>
</template>

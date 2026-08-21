<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import axios from 'axios'
import AuthCardShell from '@/components/auth/AuthCardShell.vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { ensureCsrfCookie } from '@/stores/auth'

const email = ref('')
const isSending = ref(false)
const errorMessage = ref('')
const sent = ref(false)

const submit = async () => {
  if (isSending.value) return

  isSending.value = true
  errorMessage.value = ''

  try {
    await ensureCsrfCookie()

    // NOT '/forgot-password'. That path is ALSO this SPA's own router page
    // route, and neither the Vercel rewrite nor the Vite dev proxy can tell a
    // page GET from this POST — rewrites match on path, never on method. Same
    // indirection as '/auth/login'; the proxy maps it back to the API's real
    // '/forgot-password', so no backend route changed.
    await api.post('/auth/forgot-password', { email: email.value })
    sent.value = true
  } catch (error) {
    // 429 is a real outcome here, not a defensive branch: this endpoint is
    // throttled to 6/min (routes/auth.php), and categorizeError has no bucket
    // for it — it would surface Laravel's bare "Too Many Requests".
    if (axios.isAxiosError(error) && error.response?.status === 429) {
      errorMessage.value = 'Too many attempts. Please wait a minute and try again.'

      return
    }

    const { kind, message, fieldErrors } = categorizeError(
      error,
      'We could not send the reset link. Please try again.',
    )

    // The broker's own message ("We can't find a user with that email
    // address.") arrives as a 422 keyed on `email` and is the single most
    // useful thing to show, so it is preferred over the generic fallback.
    errorMessage.value = kind === 'validation' ? (fieldErrors?.email?.[0] ?? message) : message
  } finally {
    isSending.value = false
  }
}
</script>

<template>
  <AuthCardShell
    title="Forgot your password?"
    subtitle="Enter the email address on your InternTrack account and we will send you a link to choose a new password."
  >
    <!--
      The confirmation replaces the form rather than sitting above it: leaving
      a filled-in field and a live Send button under a "check your inbox"
      message invites a second submit, which the 6/min throttle then refuses.
    -->
    <div v-if="sent" class="mt-8">
      <div class="rounded-xl border border-emerald-200 bg-emerald-50/90 px-4 py-3 text-sm text-emerald-800">
        <p class="font-semibold">Check your email.</p>
        <p class="mt-1 leading-relaxed">
          If an InternTrack account uses <span class="font-semibold">{{ email }}</span>, a reset link is on its way. It
          expires in 60 minutes.
        </p>
      </div>

      <p class="mt-4 text-xs leading-relaxed text-slate-600">
        Nothing arrives after a few minutes? Check your spam folder. If your account has no email address on file — or a
        different one — your OJT coordinator can resend your login details for you.
      </p>

      <RouterLink
        to="/login"
        class="mt-8 flex min-h-[3rem] w-full items-center justify-center rounded-full bg-linear-to-r from-blue-900 to-teal-500 px-6 py-3 text-sm font-semibold text-white shadow-md transition hover:brightness-110 focus-visible:ring-2 focus-visible:ring-blue-900 focus-visible:ring-offset-2 focus-visible:outline-none"
      >
        Back to sign in
      </RouterLink>
    </div>

    <form v-else class="mt-8" @submit.prevent="submit">
      <label class="mb-2 block text-sm font-medium text-slate-700" for="email">Email address</label>
      <div class="relative">
        <input
          id="email"
          v-model="email"
          type="email"
          name="email"
          placeholder="you@example.com"
          class="peer w-full border-b-2 border-slate-300 bg-transparent py-2 pr-9 pl-1 text-[15px] text-slate-900 outline-none placeholder:text-slate-400"
          autocomplete="email"
          required
        />
        <span
          class="pointer-events-none absolute inset-x-0 -bottom-0.5 h-0.5 origin-left scale-x-0 bg-linear-to-r from-blue-900 to-teal-500 transition-transform duration-300 peer-focus:scale-x-100 motion-reduce:transition-none"
        />
        <span
          class="pointer-events-none absolute inset-y-0 right-1 flex items-center text-slate-400 transition duration-200 peer-focus:scale-110 peer-focus:text-teal-600 motion-reduce:transition-none"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
            <rect x="3" y="5.5" width="18" height="13" rx="2.2" stroke="currentColor" stroke-width="1.7" />
            <path d="m3.8 7 8.2 6 8.2-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
          </svg>
        </span>
      </div>

      <p
        v-if="errorMessage"
        role="alert"
        class="mt-6 rounded-xl border border-rose-200 bg-rose-50/90 px-4 py-3 text-sm text-rose-700"
      >
        {{ errorMessage }}
      </p>

      <button
        type="submit"
        class="mt-8 flex min-h-[3rem] w-full items-center justify-center rounded-full bg-linear-to-r from-blue-900 to-teal-500 px-6 py-3 text-sm font-semibold text-white shadow-md transition hover:brightness-110 focus-visible:ring-2 focus-visible:ring-blue-900 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-[0.985] disabled:pointer-events-none disabled:cursor-not-allowed disabled:grayscale"
        :disabled="isSending"
      >
        <span class="flex items-center justify-center gap-2">
          <svg
            v-if="isSending"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            class="h-4 w-4 shrink-0 animate-spin"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" class="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
          </svg>
          {{ isSending ? 'Sending…' : 'Send reset link' }}
        </span>
      </button>

      <p class="mt-6 text-center text-sm text-slate-600">
        <RouterLink to="/login" class="font-semibold text-blue-900 hover:text-blue-700">Back to sign in</RouterLink>
      </p>
    </form>
  </AuthCardShell>
</template>

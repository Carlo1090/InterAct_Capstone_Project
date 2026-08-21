<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import AuthCardShell from '@/components/auth/AuthCardShell.vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { ensureCsrfCookie } from '@/stores/auth'

const route = useRoute()
const router = useRouter()

/**
 * Both halves of the link the email carries. AppServiceProvider builds it as
 * `{FRONTEND_URL}/password-reset/{token}?email={address}`, and Laravel's
 * broker needs BOTH back — the token alone identifies nothing, since the
 * password_reset_tokens row is keyed by email.
 */
const token = computed(() => String(route.params.token ?? ''))
const email = ref(typeof route.query.email === 'string' ? route.query.email : '')

const password = ref('')
const passwordConfirmation = ref('')
const showPassword = ref(false)
const isSaving = ref(false)
const errorMessage = ref('')

const canSubmit = computed(
  () => token.value !== '' && email.value !== '' && password.value !== '' && passwordConfirmation.value !== '',
)

const submit = async () => {
  if (isSaving.value || !canSubmit.value) return

  // Checked here as well as by the server so the round trip is not spent on
  // something the page already knows; the server still enforces `confirmed`.
  if (password.value !== passwordConfirmation.value) {
    errorMessage.value = 'The two passwords do not match.'

    return
  }

  isSaving.value = true
  errorMessage.value = ''

  try {
    await ensureCsrfCookie()

    // '/auth/reset-password', not '/reset-password' — same proxy-path
    // indirection as login. See ForgotPasswordPage for why.
    await api.post('/auth/reset-password', {
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })

    // Straight to the front door with a flag the login page turns into a
    // notice. Read through consumeQueryParam there, so a refresh cannot
    // replay it.
    await router.push({ path: '/login', query: { reset: '1' } })
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 429) {
      errorMessage.value = 'Too many attempts. Please wait a minute and try again.'

      return
    }

    const { kind, message, fieldErrors } = categorizeError(error, 'We could not reset your password.')

    // A dead or already-used token comes back as a 422 keyed on `email`
    // ("This password reset token is invalid."), and the password rules come
    // back keyed on `password`. Both are far more useful than the fallback.
    errorMessage.value =
      kind === 'validation'
        ? (fieldErrors?.email?.[0] ?? fieldErrors?.password?.[0] ?? message)
        : message
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <AuthCardShell
    title="Choose a new password"
    :subtitle="
      email
        ? `Setting a new password for ${email}.`
        : 'Enter the email address this reset link was sent to, then choose a new password.'
    "
  >
    <form class="mt-8" @submit.prevent="submit">
      <!--
        Normally prefilled from the link's ?email= and left visible rather than
        hidden, so someone forwarding the link to the wrong inbox can see which
        account is about to change. Editable only because some mail clients
        mangle a query string; the token still has to match it server-side.
      -->
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700" for="email">Email address</label>
        <div class="relative">
          <input
            id="email"
            v-model="email"
            type="email"
            name="email"
            placeholder="you@example.com"
            class="peer w-full border-b-2 border-slate-300 bg-transparent py-2 pr-1 pl-1 text-[15px] text-slate-900 outline-none placeholder:text-slate-400"
            autocomplete="username"
            required
          />
          <span
            class="pointer-events-none absolute inset-x-0 -bottom-0.5 h-0.5 origin-left scale-x-0 bg-linear-to-r from-blue-900 to-teal-500 transition-transform duration-300 peer-focus:scale-x-100 motion-reduce:transition-none"
          />
        </div>
      </div>

      <div class="mt-6">
        <label class="mb-2 block text-sm font-medium text-slate-700" for="password">New password</label>
        <div class="relative">
          <input
            id="password"
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            name="password"
            placeholder="At least 8 characters"
            class="peer w-full border-b-2 border-slate-300 bg-transparent py-2 pr-10 pl-1 text-[15px] text-slate-900 outline-none placeholder:tracking-normal placeholder:text-slate-400"
            :class="!showPassword && password !== '' && 'tracking-[0.2em]'"
            autocomplete="new-password"
            required
          />
          <span
            class="pointer-events-none absolute inset-x-0 -bottom-0.5 h-0.5 origin-left scale-x-0 bg-linear-to-r from-blue-900 to-teal-500 transition-transform duration-300 peer-focus:scale-x-100 motion-reduce:transition-none"
          />
          <!--
            A plain toggle, not LoginPage's press-and-hold. There the password
            is one the user already knows and is only confirming; here they are
            inventing one and need to read it back while typing, which a
            hold-to-reveal button cannot do one-handed on a phone.
          -->
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center rounded px-1 text-slate-400 transition select-none hover:text-slate-600 focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:outline-none"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            @click="showPassword = !showPassword"
          >
            <svg
              v-if="!showPassword"
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              class="pointer-events-none h-4 w-4"
            >
              <path
                d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12s-3.75 6.75-9.75 6.75S2.25 12 2.25 12Z"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
              <circle cx="12" cy="12" r="2.75" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg
              v-else
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              class="pointer-events-none h-4 w-4"
            >
              <path
                d="M3 3l18 18M10.6 10.6a2.75 2.75 0 0 0 3.8 3.8M6.4 6.5C4 8.2 2.25 12 2.25 12s3.75 6.75 9.75 6.75c1.6 0 3-.36 4.2-.94M17.9 15.3c2-1.7 3.85-3.3 3.85-3.3S18 5.25 12 5.25c-.7 0-1.37.07-2 .2"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </button>
        </div>
      </div>

      <div class="mt-6">
        <label class="mb-2 block text-sm font-medium text-slate-700" for="password_confirmation">
          Confirm new password
        </label>
        <div class="relative">
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
            :type="showPassword ? 'text' : 'password'"
            name="password_confirmation"
            placeholder="Type it again"
            class="peer w-full border-b-2 border-slate-300 bg-transparent py-2 pr-1 pl-1 text-[15px] text-slate-900 outline-none placeholder:tracking-normal placeholder:text-slate-400"
            :class="!showPassword && passwordConfirmation !== '' && 'tracking-[0.2em]'"
            autocomplete="new-password"
            required
          />
          <span
            class="pointer-events-none absolute inset-x-0 -bottom-0.5 h-0.5 origin-left scale-x-0 bg-linear-to-r from-blue-900 to-teal-500 transition-transform duration-300 peer-focus:scale-x-100 motion-reduce:transition-none"
          />
        </div>
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
        :disabled="isSaving || !canSubmit"
      >
        <span class="flex items-center justify-center gap-2">
          <svg
            v-if="isSaving"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            class="h-4 w-4 shrink-0 animate-spin"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" class="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
          </svg>
          {{ isSaving ? 'Saving…' : 'Reset password' }}
        </span>
      </button>

      <p class="mt-6 text-center text-sm text-slate-600">
        <RouterLink to="/forgot-password" class="font-semibold text-blue-900 hover:text-blue-700">
          Request a new link
        </RouterLink>
        <span class="mx-2 text-slate-400">&middot;</span>
        <RouterLink to="/login" class="font-semibold text-blue-900 hover:text-blue-700">Back to sign in</RouterLink>
      </p>
    </form>
  </AuthCardShell>
</template>

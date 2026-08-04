<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ensureCsrfCookie, useAuthStore } from '@/stores/auth'
import { roleRedirect } from '@/router/index.ts'
import { consumeQueryParam, googleErrorMessage, googleLoginUrl } from '@/lib/googleAuth'

const auth = useAuthStore()
const router = useRouter()

const identifier = ref('')
const password = ref('')
const errorMessage = ref('')
const isLoading = ref(false)
const showPassword = ref(false)

/**
 * The eye icon is PRESS-AND-HOLD, not click-to-toggle: the password is only ever
 * plain text while a finger/mouse button is physically held down, so it cannot be
 * left revealed on a shared MDC lab machine by a stray click. A plain click/tap
 * does nothing at all — the press reveals, the release re-masks.
 *
 * Three details below are load-bearing, each found by watching this fail in a
 * real browser rather than reasoned about up front:
 *
 *  1. The two eye icons are `pointer-events-none` so the BUTTON is the event
 *     target, not the `<svg>`. Revealing swaps the icon via `v-if`/`v-else`,
 *     which destroys the very node the touch started on; a touch sequence is
 *     dispatched to its original target, so with the icon as target the
 *     `touchend` reached a detached node, never bubbled, and the password
 *     stayed in plain text after the finger lifted. Measured: adding the static
 *     `@touchend` alone did NOT fix it; this did.
 *  2. `document` listeners are added on reveal to catch a release OFF the
 *     button. Chromium implicitly captures mouse events on the element that
 *     received `mousedown`, so press → drag away → release fires neither
 *     `mouseup` nor `mouseleave` on the button.
 *  3. That same capture is why drag-off is detected by hit-testing the cursor
 *     against the button's rect on `mousemove`: `mouseleave` never arrives
 *     mid-press. The `@mouseleave` binding stays as a cheap backstop for
 *     engines that do not capture.
 *
 * The template's static `@mouseup`/`@touchend`/`@touchcancel` are the ordinary
 * in-place release path; they overlap with the `document` set, which is
 * harmless because hiding is idempotent.
 */
const eyeButton = ref<HTMLButtonElement | null>(null)

const hidePassword = () => {
  showPassword.value = false
  document.removeEventListener('mouseup', hidePassword)
  document.removeEventListener('mousemove', hideIfPointerLeftEye)
  document.removeEventListener('touchend', hidePassword)
  document.removeEventListener('touchcancel', hidePassword)
}

const hideIfPointerLeftEye = (event: MouseEvent) => {
  const rect = eyeButton.value?.getBoundingClientRect()
  if (!rect) return
  const inside =
    event.clientX >= rect.left &&
    event.clientX <= rect.right &&
    event.clientY >= rect.top &&
    event.clientY <= rect.bottom
  if (!inside) hidePassword()
}

const revealPassword = () => {
  if (showPassword.value) return
  showPassword.value = true
  document.addEventListener('mouseup', hidePassword)
  document.addEventListener('mousemove', hideIfPointerLeftEye)
  document.addEventListener('touchend', hidePassword)
  document.addEventListener('touchcancel', hidePassword)
}

// Never leave a stray listener behind if the page unmounts mid-press.
onUnmounted(hidePassword)

/**
 * Keep BOTH typed credentials alive across a refresh, in sessionStorage.
 *
 * SECURITY — this deliberately persists a password, at the project owner's
 * explicit instruction (2026-07-30), and REVERSES the previous rule here (which
 * stored the username only). Understand the trade before extending it:
 * sessionStorage is plain text readable by any JavaScript on the page, so a
 * single XSS bug anywhere in the SPA turns this into credential theft, and on
 * the shared MDC lab machines this app targets it stays readable via DevTools
 * until the TAB is closed — not merely until the user walks away. Clearing on a
 * successful login bounds the exposure to an in-progress or abandoned attempt.
 *
 * This is written inline rather than through `useFormDraft`, on purpose. That
 * helper backs roughly a dozen other forms and its contract is "never return a
 * credential from read()"; routing a password through it would relax that
 * guarantee for every one of those call sites instead of just this page.
 *
 * Note the browser's own password manager already does this properly — the
 * input carries `autocomplete="current-password"` and remains the encrypted,
 * OS-protected path. This runs alongside it.
 */
const USERNAME_STORAGE_KEY = 'interntrack_login_username'
const PASSWORD_STORAGE_KEY = 'interntrack_login_password'

/**
 * Every access is best-effort: Safari private mode and a full quota THROW
 * rather than merely failing, and losing a draft must never break the login
 * form itself.
 */
const readStored = (key: string): string | null => {
  try {
    return sessionStorage.getItem(key)
  } catch {
    return null
  }
}

const writeStored = (key: string, value: string): void => {
  try {
    sessionStorage.setItem(key, value)
  } catch {
    /* storage unavailable or full — drop it rather than break typing */
  }
}

const clearStoredCredentials = (): void => {
  try {
    sessionStorage.removeItem(USERNAME_STORAGE_KEY)
    sessionStorage.removeItem(PASSWORD_STORAGE_KEY)
  } catch {
    /* nothing to do — see readStored */
  }
}

// Written synchronously on every change, NOT debounced. A pending debounced
// write is exactly what made `clear()` fail elsewhere in this app (the timer
// fired ~300ms later and re-wrote what had just been cleared); with direct
// writes, clearing on a successful login cannot be undone by a stale timer.
watch(identifier, (value) => writeStored(USERNAME_STORAGE_KEY, value))
watch(password, (value) => writeStored(PASSWORD_STORAGE_KEY, value))

// A full page navigation, not XHR — Google needs the browser itself.
const signInWithGoogle = () => {
  window.location.href = googleLoginUrl()
}

onMounted(() => {
  // Warm Sanctum's XSRF cookie NOW, while the user is still typing, so it is no
  // longer one of the calls blocking the Login button. Fire-and-forget with the
  // error swallowed on purpose — login() awaits the same memoised promise, so a
  // failed prime just means it is fetched then, exactly as it used to be. This
  // must never break the form, matching the try/catch posture used for
  // sessionStorage above.
  void ensureCsrfCookie().catch(() => {})

  // The OAuth callback bounces failures back here as ?google_error=<code>.
  errorMessage.value = googleErrorMessage(consumeQueryParam('google_error'))

  // Pre-fill from the previous visit to this tab. Assigning these triggers the
  // watchers above, which simply rewrite the identical value — harmless.
  const storedUsername = readStored(USERNAME_STORAGE_KEY)
  const storedPassword = readStored(PASSWORD_STORAGE_KEY)
  if (storedUsername !== null) identifier.value = storedUsername
  if (storedPassword !== null) password.value = storedPassword
})

const login = async () => {
  errorMessage.value = ''
  isLoading.value = true

  try {
    await auth.login(identifier.value, password.value)
    // Signed in successfully — the stored copies have served their purpose, so
    // drop them immediately rather than leaving a password sitting in this tab's
    // storage for the rest of the session. A FAILED login deliberately keeps
    // both, since that is the case where retyping is the actual annoyance.
    clearStoredCredentials()
    // Awaited so `isLoading` stays true until the destination is actually on
    // screen. router.push resolves only after the guard passes AND the lazy
    // layout + page chunks have loaded; leaving it unawaited flipped the button
    // back to "Login" while the page was still visibly stationary.
    await router.push(roleRedirect(auth.role))
  } catch {
    errorMessage.value = 'Invalid credentials. Please try again.'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen w-full bg-slate-50">
    <!--
      Branding panel. The boundary is a straight edge on purpose: the SVG wave
      divider that used to sit here was removed. Gradient stops are unchanged
      (blue-900 / blue-800 / teal-500) — nothing here shifts colour intensity.
    -->
    <section
      class="relative hidden w-[42%] shrink-0 flex-col items-center justify-center overflow-hidden bg-linear-to-br from-blue-900 via-blue-800 to-teal-500 px-12 py-16 text-white md:flex"
    >
      <div class="text-center">
        <div
          class="mx-auto mb-9 flex h-44 w-44 items-center justify-center rounded-full bg-white shadow-xl"
        >
          <img
            src="/images/mdc-logo.png"
            alt="Mater Dei College seal"
            class="h-36 w-36 rounded-full object-contain"
          />
        </div>

        <h1 class="text-4xl font-bold tracking-tight">Welcome to InternTrack</h1>

        <div class="mx-auto mt-5 h-px w-16 bg-white/30" />

        <p class="mx-auto mt-5 max-w-sm text-sm leading-relaxed text-blue-100">
          Internship Journal and Progress Monitoring System
          <span class="mt-1 block">Mater Dei College &middot; Tubigon, Bohol</span>
        </p>
      </div>

      <p class="absolute inset-x-0 bottom-8 text-center text-xs text-blue-200">
        &copy; Mater Dei College
      </p>
    </section>

    <!-- Form panel -->
    <section class="flex flex-1 items-center justify-center px-6 py-12">
      <div class="w-full max-w-sm">
        <div class="mb-6 flex justify-center md:hidden">
          <img
            src="/images/mdc-logo.png"
            alt="Mater Dei College seal"
            class="h-24 w-24 rounded-full object-contain"
          />
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
          <h2 class="text-3xl font-bold tracking-tight text-slate-900">Welcome Back!</h2>
          <p class="mt-2 mb-8 text-sm text-slate-500">Sign in to continue to your dashboard.</p>

          <!--
            A real <form>, so Enter submits from EITHER field. The button was
            previously type="button" with @keyup.enter bound only to the password
            input, so Enter did nothing while the username field had focus.
          -->
          <form class="space-y-5" @submit.prevent="login">
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="identifier">
                Username
              </label>
              <div class="flex items-center gap-3">
                <span
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-teal-500 text-white"
                >
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
                  name="username"
                  placeholder="Enter your username"
                  class="w-full border-b-2 border-slate-200 bg-transparent px-1 py-2 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500"
                  autocomplete="username"
                  required
                />
              </div>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="password">
                Password
              </label>
              <div class="flex items-center gap-3">
                <span
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-500 text-white"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                    <path
                      d="M12 2a4 4 0 0 0-4 4v3H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a4 4 0 0 0-4-4Zm0 2a2 2 0 0 1 2 2v3h-4V6a2 2 0 0 1 2-2Z"
                      fill="currentColor"
                    />
                  </svg>
                </span>
                <div class="relative w-full">
                  <input
                    id="password"
                    v-model="password"
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    placeholder="Enter your password"
                    class="w-full border-b-2 border-slate-200 bg-transparent py-2 pr-9 pl-1 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500"
                    autocomplete="current-password"
                    required
                  />
                  <button
                    ref="eyeButton"
                    type="button"
                    class="absolute top-1/2 right-0 -translate-y-1/2 rounded p-1 text-slate-400 transition select-none hover:text-slate-600"
                    aria-label="Press and hold to show password"
                    @mousedown.prevent="revealPassword"
                    @mouseup="hidePassword"
                    @mouseleave="hidePassword"
                    @touchstart.prevent="revealPassword"
                    @touchend="hidePassword"
                    @touchcancel="hidePassword"
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
            </div>

            <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
              {{ errorMessage }}
            </p>

            <button
              type="submit"
              class="w-full rounded-full bg-linear-to-r from-blue-900 to-teal-500 px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:opacity-90 disabled:cursor-not-allowed disabled:grayscale"
              :disabled="isLoading"
            >
              {{ isLoading ? 'Logging in...' : 'Login' }}
            </button>
          </form>

          <div class="my-5 flex items-center gap-3">
            <span class="h-px flex-1 bg-slate-200" />
            <span class="text-xs font-medium text-slate-400">or</span>
            <span class="h-px flex-1 bg-slate-200" />
          </div>

          <button
            type="button"
            class="flex w-full items-center justify-center gap-2.5 rounded-full border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            @click="signInWithGoogle"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 18 18" class="h-4 w-4">
              <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z" />
              <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z" />
              <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z" />
              <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z" />
            </svg>
            Sign in with Google
          </button>

          <p class="mt-3 text-center text-xs text-slate-500">
            Google sign-in works only after you verify your email from Edit Profile.
          </p>
        </div>
      </div>
    </section>
  </main>
</template>

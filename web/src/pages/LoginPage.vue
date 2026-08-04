<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import type { CSSProperties } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
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

/**
 * Card shake on a failed sign-in. Driven by watching `errorMessage` rather than
 * by touching `login()`, so the submit handler stays exactly as it was. The
 * false → nextTick → true hop restarts the animation when the same error fires
 * twice in a row (re-adding an already-present class would not).
 */
const shake = ref(false)

watch(errorMessage, (message) => {
  if (!message) return
  shake.value = false
  void nextTick(() => {
    shake.value = true
  })
})

/**
 * Entrance stagger. ONE ref drives the whole sequence: `entered` lands on
 * <main>, and the scoped `.entered .reveal` rule releases every marked element
 * at once. Each element's own offset is an inline `--d` custom property that
 * the CSS reads as `transition-delay`, so eight staggered elements cost one
 * class toggle rather than eight timers — and no timer can fire after unmount.
 *
 * The mobile media query halves every offset via `calc(var(--d) / 2)`, which is
 * only possible because the delay travels as a custom property; an inline
 * `transition-delay` could not be overridden by a stylesheet at all.
 */
const entered = ref(false)

const delay = (ms: number): CSSProperties => ({ '--d': `${ms}ms` })

/**
 * Pointer-tracked card tilt, desktop only.
 *
 * Deliberately capped at ±4°: enough to read as depth when the cursor crosses
 * the card, not enough to distort the text people are trying to type into.
 *
 * Every gate is re-read from `matchMedia` on each move rather than captured
 * once at setup, so resizing the window, switching to a touchscreen, or turning
 * on Reduce Motion mid-session takes effect immediately with no listener to
 * register or tear down. The two handlers are bound in the template, so Vue
 * removes them with the element — there is no manual listener to leak; the
 * `onUnmounted` reset below is belt-and-braces for the tilt STATE.
 */
const MAX_TILT_DEG = 4

const tiltX = ref(0)
const tiltY = ref(0)
const isTilting = ref(false)

const canTilt = (): boolean => {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
  return (
    window.matchMedia('(pointer: fine)').matches &&
    window.matchMedia('(min-width: 1024px)').matches &&
    !window.matchMedia('(prefers-reduced-motion: reduce)').matches
  )
}

const resetTilt = () => {
  tiltX.value = 0
  tiltY.value = 0
  isTilting.value = false
}

const handleTilt = (event: PointerEvent) => {
  if (!canTilt()) {
    if (isTilting.value) resetTilt()
    return
  }

  const target = event.currentTarget
  if (!(target instanceof HTMLElement)) return

  const rect = target.getBoundingClientRect()
  if (rect.width === 0 || rect.height === 0) return

  // Normalised to -0.5..0.5 from the card's centre.
  const offsetX = (event.clientX - rect.left) / rect.width - 0.5
  const offsetY = (event.clientY - rect.top) / rect.height - 0.5

  // rotateY follows the horizontal axis; rotateX is inverted so the edge nearest
  // the cursor tips toward the viewer rather than away from them.
  tiltY.value = offsetX * 2 * MAX_TILT_DEG
  tiltX.value = -offsetY * 2 * MAX_TILT_DEG
  isTilting.value = true
}

/**
 * Flat returns an EMPTY object on purpose. An inline `transform: none` would
 * outrank the card-shake keyframes, so the failed-login shake would silently
 * stop playing on any device that never tilts.
 */
const cardStyle = computed<CSSProperties>(() =>
  tiltX.value === 0 && tiltY.value === 0
    ? {}
    : { transform: `rotateX(${tiltX.value.toFixed(2)}deg) rotateY(${tiltY.value.toFixed(2)}deg)` },
)

// Never leave a stray listener behind if the page unmounts mid-press.
onUnmounted(() => {
  hidePassword()
  resetTilt()
})

// A full page navigation, not XHR — Google needs the browser itself.
const signInWithGoogle = () => {
  window.location.href = googleLoginUrl()
}

onMounted(() => {
  // The OAuth callback bounces failures back here as ?google_error=<code>.
  errorMessage.value = googleErrorMessage(consumeQueryParam('google_error'))

  // Pre-fill from the previous visit to this tab. Assigning these triggers the
  // watchers above, which simply rewrite the identical value — harmless.
  const storedUsername = readStored(USERNAME_STORAGE_KEY)
  const storedPassword = readStored(PASSWORD_STORAGE_KEY)
  if (storedUsername !== null) identifier.value = storedUsername
  if (storedPassword !== null) password.value = storedPassword

  // One frame later, so the browser paints the pre-transition state first —
  // flipping this synchronously would land the elements already in place and
  // skip the animation entirely.
  void nextTick(() => {
    requestAnimationFrame(() => {
      entered.value = true
    })
  })
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
    router.push(roleRedirect(auth.role))
  } catch {
    errorMessage.value = 'Invalid credentials. Please try again.'
  } finally {
    isLoading.value = false
  }
}
</script>
<template>
  <main
    class="bg-drift relative flex min-h-dvh w-full overflow-hidden bg-linear-to-br from-blue-900 via-blue-800 to-teal-500"
    :class="entered && 'entered'"
  >
    <!-- Ambient drift. Decorative only, and fully stilled under prefers-reduced-motion. -->
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden">
      <span class="blob blob-a absolute -left-24 top-[-10%] h-[28rem] w-[28rem] rounded-full bg-linear-to-br from-teal-300 to-blue-400 opacity-25 blur-3xl" />
      <span class="blob blob-b absolute -right-32 bottom-[-15%] h-[32rem] w-[32rem] rounded-full bg-linear-to-tr from-sky-300 to-teal-200 opacity-25 blur-3xl" />
    </div>

    <!--
      Desktop brand panel. Below lg the same content is stacked above the card.

      Both columns are equal-height flex siblings that centre their own content,
      so the group's midpoint and the card's already coincide geometrically. The
      asymmetric padding (pt-24 / pb-20) is an OPTICAL correction: the 176px seal
      makes the block top-heavy, so its perceived centre sits above its geometric
      one and it reads as riding high. The extra 16px of top padding drops it by
      8px. `pb-20` doubles as clearance for the absolutely-positioned copyright
      line at `bottom-8`, which the group can therefore never collide with.
    -->
    <section class="relative hidden w-[46%] shrink-0 flex-col items-center justify-center px-12 pt-24 pb-20 text-white lg:flex">
      <div class="text-center">
        <div class="reveal" :style="delay(0)">
          <div class="logo-float mx-auto mb-9 flex h-44 w-44 items-center justify-center rounded-full bg-white shadow-xl">
            <img src="/images/mdc-logo.png" alt="Mater Dei College seal" class="h-36 w-36 rounded-full object-contain" />
          </div>
        </div>

        <div class="reveal" :style="delay(80)">
          <h1 class="text-4xl font-bold tracking-tight">Welcome to InternTrack</h1>
        </div>

        <div class="reveal" :style="delay(160)">
          <div class="mx-auto mt-5 h-px w-16 bg-white/30" />

          <p class="mx-auto mt-5 max-w-sm text-sm leading-relaxed text-blue-100">
            Internship Journal and Progress Monitoring System
            <span class="mt-1 block">Mater Dei College &middot; Tubigon, Bohol</span>
          </p>
        </div>
      </div>

      <p class="reveal absolute inset-x-0 bottom-8 text-center text-xs text-blue-200" :style="delay(500)">
        &copy; Mater Dei College
      </p>
    </section>

    <!-- Card column -->
    <section class="relative flex flex-1 items-center justify-center px-5 py-10 sm:px-6">
      <div class="w-full max-w-sm">
        <!-- Stacked brand block, below lg only. -->
        <div class="mb-6 text-center text-white lg:hidden">
          <div class="reveal" :style="delay(0)">
            <img
              src="/images/mdc-logo.png"
              alt="Mater Dei College seal"
              class="logo-float mx-auto h-20 w-20 rounded-full bg-white object-contain p-1 shadow-lg"
            />
          </div>
          <div class="reveal" :style="delay(80)">
            <h1 class="mt-4 text-2xl font-bold tracking-tight">Welcome to InternTrack</h1>
          </div>
          <div class="reveal" :style="delay(160)">
            <p class="mx-auto mt-2 max-w-xs text-sm leading-relaxed text-blue-100">
              Internship Journal and Progress Monitoring System
              <span class="mt-1 block">Mater Dei College &middot; Tubigon, Bohol</span>
            </p>
          </div>
        </div>

        <!--
          The tilt SCENE owns the perspective and the pointer tracking; the card
          itself owns the rotation. They are split because the scene also carries
          the entrance transform, and one element cannot hold two.
        -->
        <div
          class="reveal tilt-scene"
          :style="delay(240)"
          @pointermove="handleTilt"
          @pointerleave="resetTilt"
        >
          <!--
            `bg-white/75`, not the /60 of the reference: over the darkest gradient
            stop (blue-900) a 60% white card composites to ~#a5b0d0, on which
            slate-600 body text measures 3.50:1 — well under WCAG AA. /70 reaches
            only 4.35:1. /75 composites to ~#c7ceE2 and clears 4.82:1.
          -->
          <div
            class="frost tilt relative overflow-hidden rounded-2xl border border-white/50 bg-white/75 p-6 shadow-2xl backdrop-blur-2xl sm:p-8"
            :class="[shake && 'shake', isTilting && 'is-tilting']"
            :style="cardStyle"
            @animationend="shake = false"
          >
            <!-- Sits above the ::before top-edge highlight, which is positioned. -->
            <div class="relative">
              <h2 class="text-3xl font-bold tracking-tight text-slate-900">Welcome Back!</h2>
              <p class="mt-1.5 text-sm text-slate-600">Sign in to continue to your dashboard.</p>

              <!--
                A real <form>, so Enter submits from EITHER field. The button was
                previously type="button" with @keyup.enter bound only to the password
                input, so Enter did nothing while the username field had focus.

                Spacing is per-element rather than `space-y-*`: the rhythm is
                deliberately uneven (6 between the two fields, 8 before the submit
                button), which a single uniform gap cannot express.
              -->
              <form class="mt-8" @submit.prevent="login">
                <div class="reveal" :style="delay(320)">
                  <label class="mb-2 block text-sm font-medium text-slate-700" for="identifier">Username</label>
                  <!-- The input is the `peer`, so it must precede the underline and icon. -->
                  <div class="relative">
                    <input
                      id="identifier"
                      v-model="identifier"
                      type="text"
                      name="username"
                      placeholder="Enter your username"
                      class="peer w-full border-b-2 border-slate-300 bg-transparent py-2 pr-9 pl-1 text-[15px] text-slate-900 outline-none placeholder:text-slate-400"
                      autocomplete="username"
                      required
                    />
                    <span
                      class="pointer-events-none absolute inset-x-0 -bottom-0.5 h-0.5 origin-left scale-x-0 bg-linear-to-r from-blue-900 to-teal-500 transition-transform duration-300 peer-focus:scale-x-100 motion-reduce:transition-none"
                    />
                    <!--
                      `inset-y-0 flex items-center` rather than a tuned `bottom-*`:
                      every trailing control in BOTH fields uses it, so they share
                      one vertical centreline by construction. The lock and eye
                      previously sat on `bottom-2.5` and `bottom-1.5`, two pixels
                      apart, which is what read as crowding.
                    -->
                    <span
                      class="pointer-events-none absolute inset-y-0 right-1 flex items-center text-slate-400 transition duration-200 peer-focus:scale-110 peer-focus:text-teal-600 motion-reduce:transition-none"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                        <circle cx="12" cy="8.5" r="3.6" stroke="currentColor" stroke-width="1.7" />
                        <path d="M4.5 20c.9-3.6 3.9-5.6 7.5-5.6s6.6 2 7.5 5.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                      </svg>
                    </span>
                  </div>
                </div>

                <div class="reveal mt-6" :style="delay(380)">
                  <label class="mb-2 block text-sm font-medium text-slate-700" for="password">Password</label>
                  <div class="relative">
                    <!--
                      The wide letter-spacing applies ONLY to real masked input:
                      it evens out the bullet run, but on the placeholder it would
                      stretch "Enter your password" into something unreadable.
                    -->
                    <input
                      id="password"
                      v-model="password"
                      :type="showPassword ? 'text' : 'password'"
                      name="password"
                      placeholder="Enter your password"
                      class="peer w-full border-b-2 border-slate-300 bg-transparent py-2 pr-16 pl-1 text-[15px] text-slate-900 outline-none placeholder:tracking-normal placeholder:text-slate-400"
                      :class="!showPassword && password !== '' && 'tracking-[0.2em]'"
                      autocomplete="current-password"
                      required
                    />
                    <span
                      class="pointer-events-none absolute inset-x-0 -bottom-0.5 h-0.5 origin-left scale-x-0 bg-linear-to-r from-blue-900 to-teal-500 transition-transform duration-300 peer-focus:scale-x-100 motion-reduce:transition-none"
                    />
                    <!--
                      Right-edge geometry, matched to the username field on the
                      GLYPH rather than the box: the eye button is `right-0` with
                      `px-1`, so its 16px icon ends 4px from the input's right
                      edge — exactly where the username field's person icon ends
                      (`right-1`, no padding). The lock then sits at `right-8`,
                      leaving a 12px gap between the two glyphs.
                    -->
                    <span
                      class="pointer-events-none absolute inset-y-0 right-8 flex items-center text-slate-400 transition duration-200 peer-focus:scale-110 peer-focus:text-teal-600 motion-reduce:transition-none"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                        <rect x="4.5" y="10" width="15" height="10.5" rx="2.2" stroke="currentColor" stroke-width="1.7" />
                        <path d="M8 10V7.5a4 4 0 0 1 8 0V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                      </svg>
                    </span>
                    <button
                      ref="eyeButton"
                      type="button"
                      class="absolute inset-y-0 right-0 flex items-center rounded px-1 text-slate-400 transition select-none hover:text-slate-600 focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:outline-none"
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

                <p
                  v-if="errorMessage"
                  role="alert"
                  class="mt-6 rounded-xl border border-rose-200 bg-rose-50/90 px-4 py-3 text-sm text-rose-700"
                >
                  {{ errorMessage }}
                </p>

                <!--
                  The button is `w-full`, so its WIDTH is fixed by the column and
                  never moved. What did shift was the label, when the spinner
                  appeared beside it — so the spinner's 1rem slot is always in the
                  DOM and only its opacity changes.
                -->
                <!--
                  `.reveal` lives on a WRAPPER, never on the button itself. Its
                  `transition` shorthand is scoped, so it outranks Tailwind's
                  `transition` utility (attribute selector = higher specificity)
                  and would have replaced the button's own hover transition with
                  a 500ms opacity/transform one carrying a 440ms delay — hover
                  brightness would simply have stopped working.
                -->
                <div class="reveal mt-8" :style="delay(440)">
                  <!--
                    The label is centred by a single inner span, and the spinner
                    is `v-if`'d INSIDE it. Previously the spinner was always in
                    the DOM with only its opacity toggled, so its 1rem box plus
                    the row's 0.5rem gap permanently reserved 24px to the label's
                    left — the flex row centred `[icon][gap][label]` as a unit,
                    which pushed the visible text 12px off the button's true
                    centre. Nothing else in the button was asymmetric.
                  -->
                  <button
                    type="submit"
                    class="sheen relative flex min-h-[3rem] w-full items-center justify-center overflow-hidden rounded-full bg-linear-to-r from-blue-900 to-teal-500 px-6 py-3 text-sm font-semibold text-white shadow-md transition hover:brightness-110 focus-visible:ring-2 focus-visible:ring-blue-900 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-[0.985] disabled:pointer-events-none disabled:cursor-not-allowed disabled:grayscale"
                    :disabled="isLoading"
                  >
                    <!-- Sheen: absolutely positioned, so it never enters the flex row. -->
                    <span aria-hidden="true" class="sheen-bar pointer-events-none absolute inset-y-0 left-0 w-[45%]" />

                    <span class="relative z-10 flex items-center justify-center gap-2">
                      <svg
                        v-if="isLoading"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        class="h-4 w-4 shrink-0 animate-spin"
                        aria-hidden="true"
                      >
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" class="opacity-25" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                      </svg>
                      {{ isLoading ? 'Signing in…' : 'Login' }}
                    </span>
                  </button>
                </div>
              </form>

              <div class="reveal" :style="delay(500)">
                <div class="mt-6 flex items-center gap-4">
                  <span class="h-px flex-1 bg-slate-300" />
                  <span class="text-xs font-medium text-slate-600">or</span>
                  <span class="h-px flex-1 bg-slate-300" />
                </div>

                <!--
                  Same centred-span structure as the submit button. The Google
                  glyph is UNCONDITIONAL, so centring the icon+label pair as one
                  unit is correct here — there is no state in which it vanishes
                  and leaves reserved space behind, which was the submit button's
                  actual defect.
                -->
                <button
                  type="button"
                  class="mt-6 flex min-h-[3rem] w-full items-center justify-center rounded-full border border-white/60 bg-white/70 px-6 py-3 text-sm font-semibold text-slate-700 shadow-sm ring-0 ring-white/0 backdrop-blur transition hover:-translate-y-px hover:border-white hover:bg-white hover:ring-2 hover:ring-white/60 focus-visible:ring-2 focus-visible:ring-blue-900 focus-visible:ring-offset-2 focus-visible:outline-none"
                  @click="signInWithGoogle"
                >
                  <span class="flex items-center justify-center gap-2.5">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 18 18" class="h-4 w-4 shrink-0">
                      <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z" />
                      <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z" />
                      <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z" />
                      <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z" />
                    </svg>
                    Sign in with Google
                  </span>
                </button>

                <p class="mt-4 text-center text-xs text-slate-600">
                  Google sign-in works only after you verify your email from Edit Profile.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>
</template>

<style scoped>
/*
 * Without backdrop-filter the card would otherwise render as flat 75% white over
 * the gradient, which drops the body text under AA. Fall back to solid.
 */
@supports not (backdrop-filter: blur(1px)) {
  .frost {
    background-color: #fff;
  }
}

/*
 * Top-edge highlight — the thin band of light that sells a pane of glass. Kept
 * on a pseudo-element so it never enters the flow; the card's content sits in a
 * `relative` wrapper so it paints above this.
 */
.frost::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 5rem;
  background: linear-gradient(to bottom, rgb(255 255 255 / 0.5), transparent);
  pointer-events: none;
}

/* ---- Entrance stagger -------------------------------------------------- */

/*
 * The offset arrives as an inline `--d` custom property rather than an inline
 * `transition-delay`, which is what lets the mobile query below halve it — a
 * stylesheet cannot override an inline declaration, but it can re-derive from a
 * custom property.
 */
.reveal {
  opacity: 0;
  transform: translateY(16px);
  transition:
    opacity 500ms cubic-bezier(0.22, 1, 0.36, 1),
    transform 500ms cubic-bezier(0.22, 1, 0.36, 1);
  transition-delay: var(--d, 0ms);
}

.entered .reveal {
  opacity: 1;
  transform: translateY(0);
}

/* ---- Living background ------------------------------------------------- */

@keyframes bg-drift {
  from {
    background-position: 0% 50%;
  }
  to {
    background-position: 100% 50%;
  }
}

.bg-drift {
  background-size: 200% 200%;
  animation: bg-drift 18s ease-in-out infinite alternate;
}

@keyframes blob-drift-a {
  from {
    transform: translate3d(0, 0, 0) scale(1);
  }
  to {
    transform: translate3d(3rem, 2.5rem, 0) scale(1.08);
  }
}

@keyframes blob-drift-b {
  from {
    transform: translate3d(0, 0, 0) scale(1.05);
  }
  to {
    transform: translate3d(-2.5rem, -3rem, 0) scale(1);
  }
}

.blob-a {
  animation: blob-drift-a 22s ease-in-out infinite alternate;
}

.blob-b {
  animation: blob-drift-b 28s ease-in-out infinite alternate;
}

/* ---- Logo float -------------------------------------------------------- */

@keyframes logo-float {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-8px);
  }
}

/* The shadow is a static filter — only the transform animates. */
.logo-float {
  animation: logo-float 6s ease-in-out infinite;
  filter: drop-shadow(0 12px 22px rgb(15 23 42 / 0.28));
}

/* ---- Card depth -------------------------------------------------------- */

.tilt-scene {
  perspective: 1000px;
}

/* The rest state: a 400ms glide back to flat when the pointer leaves. */
.tilt {
  transition: transform 400ms cubic-bezier(0.22, 1, 0.36, 1);
}

/* While tracking, a much shorter follow so the card does not lag the cursor. */
.tilt.is-tilting {
  transition: transform 120ms ease-out;
}

/* ---- Autofill ---------------------------------------------------------- */

/*
 * Chrome and Safari paint a hard yellow over an autofilled field and ignore
 * `background-color`. The only lever is a huge INSET box-shadow, which paints
 * inside the border box and covers it — tinted here to sit against the frosted
 * card rather than reading as a white block. `-webkit-text-fill-color` is
 * likewise the only way to recolour the text, since `color` is overridden too.
 * The absurd transition delay keeps the yellow from flashing in first.
 */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus,
input:-webkit-autofill:active {
  -webkit-box-shadow: 0 0 0 1000px rgb(232 237 245) inset;
  box-shadow: 0 0 0 1000px rgb(232 237 245) inset;
  -webkit-text-fill-color: #0f172a;
  caret-color: #0f172a;
  transition: background-color 9999s ease-in-out 0s;
}

/* ---- Button sheen ------------------------------------------------------ */

/*
 * A real element rather than a pseudo, so its stacking against the label's
 * `z-10` is inspectable in devtools. It is absolutely positioned and therefore
 * out of flow — it can never contribute width to the button's flex row.
 */
.sheen-bar {
  background: linear-gradient(105deg, transparent, rgb(255 255 255 / 0.45), transparent);
  transform: translateX(-180%) skewX(-12deg);
  transition: transform 700ms ease;
}

.sheen:hover .sheen-bar {
  transform: translateX(340%) skewX(-12deg);
}

/* ---- Card shake -------------------------------------------------------- */

@keyframes card-shake {
  0%,
  100% {
    transform: translateX(0);
  }
  20% {
    transform: translateX(-6px);
  }
  40% {
    transform: translateX(5px);
  }
  60% {
    transform: translateX(-3px);
  }
  80% {
    transform: translateX(2px);
  }
}

.shake {
  animation: card-shake 380ms ease-in-out;
}

/* ---- Below lg: halve the stagger, drop the pointer-only flourishes ------ */

@media (max-width: 1023px) {
  .reveal {
    transition-delay: calc(var(--d, 0ms) / 2);
  }

  .sheen-bar {
    display: none;
  }
}

/* ---- One shared reduced-motion switch ---------------------------------- */

@media (prefers-reduced-motion: reduce) {
  .bg-drift,
  .blob-a,
  .blob-b,
  .logo-float,
  .shake {
    animation: none;
  }

  .reveal {
    opacity: 1;
    transform: none;
    transition: none;
  }

  .tilt {
    transition: none;
  }

  .sheen-bar {
    display: none;
  }
}
</style>

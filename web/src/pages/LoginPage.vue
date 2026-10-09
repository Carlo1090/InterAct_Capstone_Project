<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import type { CSSProperties } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ensureCsrfCookie, useAuthStore } from '@/stores/auth'
import { roleRedirect } from '@/router/index.ts'
import { consumeQueryParam, googleErrorMessage, googleLoginUrl } from '@/lib/googleAuth'
import { categorizeError } from '@/lib/apiError'
import campusPoster from '@/assets/videos/login-campus-poster.jpg'
import campusVideoWebm from '@/assets/videos/login-campus.webm'
import campusVideoMp4 from '@/assets/videos/login-campus.mp4'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const identifier = ref('')
const password = ref('')
const errorMessage = ref('')
/**
 * An informational notice, NOT a failure.
 *
 * Deliberately separate from `errorMessage`, which is watched to shake the card
 * on bad credentials — nothing has gone wrong when the router sends someone
 * here because the account signed in on this phone is the wrong role for the
 * link they opened.
 */
const noticeMessage = ref('')

/**
 * Deliberately separate from `noticeMessage`, which renders amber. A completed
 * password reset is good news rather than the page's most actionable item, and
 * the project's colour rule reserves amber for exactly one thing per page.
 */
const successMessage = ref('')
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

/*
 * The campus background. A ~0.9 MB, 10.6s silent loop (a drone pull-back over
 * the campus, played forward then reversed so the seam never jumps), cut from
 * the college's own promo video. The 70 KB poster frame paints first and is
 * what paints first; the video is MOUNTED everywhere except under
 * prefers-reduced-motion or with Data Saver on — phones included, at the
 * project owner's request (2026-10-09). It fades in on `playing`, so a slow or
 * blocked autoplay (iOS Low Power Mode, for one) simply leaves the poster.
 */
const showVideo = ref(false)
const videoReady = ref(false)
const campusVideo = ref<HTMLVideoElement | null>(null)

/*
 * The background must never sit still. `loop` covers the normal case; this
 * covers the rest: a browser that fires `ended` anyway, and one that paused the
 * video while the tab was hidden and does not resume it on return. play() can
 * reject (autoplay policy), in which case the poster simply stays.
 */
/*
 * iOS Safari autoplays only a video that is muted AS AN ATTRIBUTE in the DOM.
 * Vue binds `muted` as a property, which can leave the attribute off, so set
 * both explicitly and kick playback once the element exists.
 */
const startVideo = () => {
  const video = campusVideo.value
  if (!video) return
  video.muted = true
  video.defaultMuted = true
  video.setAttribute('muted', '')
  void video.play().catch(() => {})
}

const keepPlaying = () => {
  const video = campusVideo.value
  if (!video || document.hidden) return
  if (video.ended) video.currentTime = 0
  if (video.paused) void video.play().catch(() => {})
}

const shouldPlayVideo = (): boolean => {
  if (typeof window === 'undefined' || !window.matchMedia) return false
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false
  const connection = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection
  return connection?.saveData !== true
}

// Never leave a stray listener behind if the page unmounts mid-press.
onUnmounted(() => {
  hidePassword()
  document.removeEventListener('visibilitychange', keepPlaying)
})

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

  showVideo.value = shouldPlayVideo()
  if (showVideo.value) {
    document.addEventListener('visibilitychange', keepPlaying)
    void nextTick(startVideo)
  }

  // The OAuth callback bounces failures back here as ?google_error=<code>.
  errorMessage.value = googleErrorMessage(consumeQueryParam('google_error'))

  // The router sends a signed-in user here when their role does not match the
  // page they opened. In practice this is one thing: a clock-in QR code opened
  // on a phone already signed in as a supervisor or coordinator. Without an
  // explanation the redirect looks like the code failed. consumeQueryParam
  // strips the flag via history.replaceState, so a refresh cannot replay it.
  //
  // No automatic sign-out: replacing someone's session uninvited is not ours
  // to do, and signing in through this form replaces it anyway.
  if (consumeQueryParam('wrong_role')) {
    noticeMessage.value =
      'That link is for a student account. Sign in with the student username to continue — you will be taken straight back.'
  }

  // ResetPasswordPage sends them here after a successful reset. Without this
  // the reset just dumps them on a login form with no confirmation that
  // anything happened, and the natural reading is that it failed.
  if (consumeQueryParam('reset')) {
    successMessage.value = 'Your password has been reset. Sign in with your new password.'
  }

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

/**
 * Where to land after a successful sign-in.
 *
 * Normally the role's own dashboard. But the router guard stashes the intended
 * path in ?redirect= when it bounces an unauthenticated visitor, and honouring
 * it is what makes the DTR QR flow work: a student scans a printed code, the
 * phone opens /student/dtr/scan?s=<token> in a browser with no session, and
 * they must come back to that exact URL — token intact — rather than to the
 * dashboard with the scan lost.
 *
 * Only same-origin relative paths are accepted. A `redirect` is attacker-supplied
 * (it rides in on a URL), so anything protocol-relative or absolute is discarded
 * to avoid turning the login page into an open redirect.
 */
const redirectTarget = (): string => {
  const requested = route.query.redirect

  if (typeof requested === 'string' && requested.startsWith('/') && !requested.startsWith('//')) {
    return requested
  }

  return roleRedirect(auth.role)
}

const login = async () => {
  errorMessage.value = ''
  successMessage.value = ''
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
    await router.push(redirectTarget())
  } catch (error) {
    // Now shows what the server actually said. This was a blanket
    // `catch { 'Invalid credentials.' }`, which was not merely lazy: the API's
    // exception handler only rendered JSON for api/* paths, so /login's
    // ValidationException came back as a 302 HTML redirect and there was no
    // message here to read. Both halves are fixed — bootstrap/app.php now
    // renders JSON for the SPA's auth endpoints too — and the difference is
    // load-bearing for a deactivated account, which LoginRequest rejects with
    // its own reason. Told "invalid credentials", that student goes and asks
    // for a password resend, which cannot possibly help them.
    const { kind, message, fieldErrors } = categorizeError(error, 'Invalid credentials. Please try again.')

    errorMessage.value =
      kind === 'validation' ? (fieldErrors?.login?.[0] ?? message) : message
  } finally {
    isLoading.value = false
  }
}
</script>
<template>
  <!--
    Layout follows the project owner's reference (2026-10-08): ONE centred white
    card at every width, the college seal on a badge straddling its top edge, a
    back link above and a copyright line below. It replaced a two-column layout
    (brand panel left, frosted card right) and a separate stacked brand block
    below lg — the card now carries the brand itself, so there is a single
    markup path for desktop and phone.

    A flex COLUMN rather than absolutely positioned header/footer: on a short
    phone the card is taller than what is left of the viewport, and in-flow
    siblings push the page into scrolling where absolute ones would overlap it.
  -->
  <main
    class="login-root relative flex min-h-dvh w-full flex-col overflow-hidden bg-linear-to-br from-blue-600 to-indigo-700"
    :class="entered && 'entered'"
  >
    <!--
      Campus background: poster image always, video only where cheap (see
      shouldPlayVideo). Decorative, so aria-hidden. The blue scrim on top keeps
      the white link and footer readable and the page in the app's own blue.
    -->
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden">
      <img :src="campusPoster" alt="" class="absolute inset-0 h-full w-full object-cover" decoding="async" fetchpriority="low" />
      <video
        v-if="showVideo"
        ref="campusVideo"
        class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700"
        :class="videoReady ? 'opacity-100' : 'opacity-0'"
        :poster="campusPoster"
        autoplay
        muted
        loop
        playsinline
        disablepictureinpicture
        preload="auto"
        @playing="videoReady = true"
        @ended="keepPlaying"
        @pause="keepPlaying"
      >
        <source :src="campusVideoWebm" type="video/webm" />
        <source :src="campusVideoMp4" type="video/mp4" />
      </video>
      <div class="absolute inset-0 bg-linear-to-br from-blue-900/70 via-blue-800/55 to-indigo-950/75" />
    </div>

    <!--
      The way back to the landing page. A RouterLink to `/` rather than
      history.back(): the login page is also reached from a bookmark, a QR code
      and the password-reset email, where "back" would lead somewhere else or
      nowhere. Plain text on the gradient, never a pill, so it cannot compete
      with Log in for the eye. Its container is the same 7xl column the landing
      page's header uses, so the link sits where that header's wordmark did.
    -->
    <header class="relative z-10 mx-auto w-full max-w-7xl px-5 pt-6 sm:px-8 sm:pt-9 lg:px-12">
      <RouterLink
        to="/"
        class="-ml-1.5 inline-flex items-center gap-1.5 rounded-full py-1.5 pr-3 pl-1.5 text-[13px] font-semibold text-white transition hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" class="h-4 w-4" aria-hidden="true">
          <path d="M12.5 4.5 7 10l5.5 5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Back to InternTrack
      </RouterLink>
    </header>

    <!--
      The top padding is clearance for the seal badge, which hangs half its
      height above the card and so sits OUTSIDE the box flexbox centres; without
      it a short viewport would slide the badge up under the back link. From sm
      it is uneven (pt-20 / pb-4) on purpose: that drops the card ~16px, to
      where the reference places it at 1440x897.
    -->
    <section class="relative flex flex-1 items-center justify-center px-4 pt-14 pb-8 sm:px-6 sm:pt-20 sm:pb-4">
      <!--
        The wrapper carries the entrance transform and the card carries the
        error shake: both are transforms, and one element cannot hold two.

        Deliberately STILL under the pointer (2026-10-08, project owner: "keep
        it simple") — the card used to tilt toward the cursor and the Log in
        button swept a sheen across on hover. Hover now changes colour only.
      -->
      <div class="reveal w-full max-w-104" :style="delay(0)">
        <!--
          NO `overflow-hidden` on the card: the badge deliberately overflows its
          top edge, and clipping would cut the seal in half. The top padding is
          half the badge plus 16px, so the heading clears it at both sizes.
        -->
        <div
          class="relative rounded-2xl bg-white px-5 pt-16 pb-8 shadow-2xl shadow-indigo-950/30 sm:px-9 sm:pt-18"
          :class="shake && 'shake'"
          @animationend="shake = false"
        >
          <!--
            Centred on the card's top edge by translating half its own size, so
            it stays centred on it whatever the badge's size at each breakpoint.
            Inside the card, so it shakes WITH the card instead of hanging still
            above a moving one.
          -->
          <div
            class="absolute top-0 left-1/2 flex h-24 w-24 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white shadow-lg ring-1 shadow-indigo-950/20 ring-slate-900/5 sm:h-28 sm:w-28"
          >
            <img
              src="/images/mdc-logo.png"
              alt="Mater Dei College seal"
              class="h-19 w-19 rounded-full object-contain sm:h-22 sm:w-22"
            />
          </div>

          <div class="reveal text-center" :style="delay(80)">
            <h1 class="text-[22px] font-bold tracking-tight text-slate-900 sm:text-2xl">Welcome to InternTrack</h1>
            <p class="mt-1.5 text-[13px] leading-5 text-slate-500">
              Internship Journal and Progress Monitoring System
            </p>
          </div>

          <!--
            A real <form>, so Enter submits from EITHER field.

            The inputs are `text-base` (16px), not the 14-15px around them: iOS
            Safari zooms the whole page into any focused input set smaller than
            16px, and does not zoom back out.
          -->
          <form class="mt-6" @submit.prevent="login">
            <div class="reveal" :style="delay(160)">
              <label class="mb-1.5 block text-sm font-semibold text-slate-900" for="identifier">Username</label>
              <input
                id="identifier"
                v-model="identifier"
                type="text"
                name="username"
                placeholder="Student ID or email"
                class="block h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-base text-slate-900 transition outline-none placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/15 motion-reduce:transition-none"
                autocomplete="username"
                required
              />
            </div>

            <div class="reveal relative mt-3" :style="delay(220)">
              <label class="mb-1.5 block text-sm font-semibold text-slate-900" for="password">Password</label>
              <div class="relative">
                <!--
                  The wide letter-spacing applies ONLY to real masked input: it
                  evens out the bullet run, but on the placeholder it would
                  stretch "Enter your password" into something unreadable.
                -->
                <input
                  id="password"
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  name="password"
                  placeholder="Enter your password"
                  class="block h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pr-12 pl-4 text-base text-slate-900 transition outline-none placeholder:tracking-normal placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/15 motion-reduce:transition-none"
                  :class="!showPassword && password !== '' && 'tracking-[0.2em]'"
                  autocomplete="current-password"
                  required
                />
                <!--
                  Press-and-hold — see `revealPassword` in the script. The icons
                  are `pointer-events-none` so the BUTTON is the touch target:
                  swapping them via v-if destroys the node a touch started on,
                  and the `touchend` then never arrives.
                -->
                <button
                  ref="eyeButton"
                  type="button"
                  class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-slate-500 transition select-none hover:text-slate-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none"
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
                    class="pointer-events-none h-5 w-5"
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
                    class="pointer-events-none h-5 w-5"
                  >
                    <path
                      d="M3 3l18 18M10.6 10.6a2.75 2.75 0 0 0 3.8 3.8M6.4 6.5C4 8.2 2.25 12 2.25 12s3.75 6.75 9.75 6.75c1.6 0 3-.36 4.2-.94M17.9 15.3c2-1.7 3.85-3.3 3.85-3.3S18 5.25 12 5.25c-.7 0-1.37.07-2 .2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </button>
              </div>

              <!--
                The only self-service way out of a lost or never-delivered
                password. Drawn in the label row, as the reference places it,
                but written AFTER the field so Tab still runs username →
                password → Log in; placed first in the DOM it would sit between
                the two fields in the tab order.
              -->
              <RouterLink
                to="/forgot-password"
                class="absolute top-0 right-0 rounded text-[13px] leading-5 font-semibold text-blue-600 transition hover:text-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none"
              >
                Forgot password?
              </RouterLink>
            </div>

            <p
              v-if="successMessage"
              class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
            >
              {{ successMessage }}
            </p>

            <p
              v-if="noticeMessage"
              class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
              {{ noticeMessage }}
            </p>

            <p
              v-if="errorMessage"
              role="alert"
              class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
            >
              {{ errorMessage }}
            </p>

            <!--
              `.reveal` lives on a WRAPPER, never on the button itself: its
              scoped `transition` shorthand outranks Tailwind's `transition`
              utility and would replace the button's hover transition with the
              entrance one, delay and all.

              The label is centred by a single inner span with the spinner
              v-if'd INSIDE it, so no reserved spinner slot ever pushes the
              text off the button's true centre.
            -->
            <div class="reveal mt-6" :style="delay(280)">
              <button
                type="submit"
                class="flex h-12 w-full items-center justify-center rounded-full bg-blue-600 px-6 text-[15px] font-semibold text-white shadow-md shadow-blue-600/25 transition-colors hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:grayscale"
                :disabled="isLoading"
              >
                <span class="flex items-center justify-center gap-2">
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
                  {{ isLoading ? 'Signing in…' : 'Log in' }}
                </span>
              </button>
            </div>
          </form>

          <div class="reveal" :style="delay(340)">
            <div class="mt-6 flex items-center gap-3">
              <span class="h-px flex-1 bg-slate-200" />
              <span class="text-xs text-slate-400">or</span>
              <span class="h-px flex-1 bg-slate-200" />
            </div>

            <!--
              Google's own sign-in button shape: white, a visible grey outline,
              the four-colour G mark, then the label — recognisable at a glance,
              where the reference's text-only pill read as plain (project owner,
              2026-10-08). The outline still ranks it second to the solid blue
              Log in. Hover is a colour change only, like every control here.

              The mark is the official multi-colour glyph and is `aria-hidden`:
              the label already names the action, so a screen reader would only
              hear "Google" twice.
            -->
            <button
              type="button"
              class="mt-6 flex h-12 w-full items-center justify-center gap-3 rounded-full border border-slate-300 bg-white px-6 text-[15px] font-semibold text-slate-800 shadow-sm shadow-slate-900/5 transition-colors hover:border-slate-400 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none"
              @click="signInWithGoogle"
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 18 18" class="h-5 w-5 shrink-0" aria-hidden="true">
                <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z" />
                <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z" />
                <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z" />
                <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z" />
              </svg>
              Sign in with Google
            </button>

            <p class="mt-4 text-center text-xs leading-5 text-slate-500">
              Google sign-in works once you've verified your email in Edit Profile.
            </p>
          </div>
        </div>
      </div>
    </section>

    <footer class="reveal relative pb-8 text-center text-xs font-medium text-blue-50" :style="delay(420)">
      &copy; Mater Dei College, Tubigon, Bohol
    </footer>
  </main>
</template>

<style scoped>
/*
 * style.css reserves the scrollbar gutter app-wide so pages do not shift
 * sideways when a scrollbar comes and goes. This page does not scroll on a
 * desktop, so the reserved strip only ever showed as a 15px white band down the
 * right edge of the full-bleed gradient. Released for this page alone — the
 * rule returns the moment the page unmounts, since `:has()` stops matching.
 */
:global(html:has(.login-root)) {
  scrollbar-gutter: auto;
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

/* ---- Autofill ---------------------------------------------------------- */

/*
 * Chrome and Safari paint a hard yellow over an autofilled field and ignore
 * `background-color`. The only lever is a huge INSET box-shadow, which paints
 * inside the border box and covers it — here the fields' own slate-50 fill, so
 * an autofilled field looks like any other. `-webkit-text-fill-color` is
 * likewise the only way to recolour the text. The absurd transition delay keeps
 * the yellow from flashing in first.
 */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus,
input:-webkit-autofill:active {
  -webkit-box-shadow: 0 0 0 1000px rgb(248 250 252) inset;
  box-shadow: 0 0 0 1000px rgb(248 250 252) inset;
  -webkit-text-fill-color: #0f172a;
  caret-color: #0f172a;
  transition: background-color 9999s ease-in-out 0s;
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

/* ---- Below lg: halve the stagger --------------------------------------- */

@media (max-width: 1023px) {
  .reveal {
    transition-delay: calc(var(--d, 0ms) / 2);
  }
}

/* ---- One shared reduced-motion switch ---------------------------------- */

@media (prefers-reduced-motion: reduce) {
  .shake {
    animation: none;
  }

  .reveal {
    opacity: 1;
    transform: none;
    transition: none;
  }
}
</style>

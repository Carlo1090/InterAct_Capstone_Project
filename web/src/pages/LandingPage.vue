<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import type { CSSProperties } from 'vue'
import { RouterLink } from 'vue-router'

/*
 * Entrance stagger, lifted wholesale from LoginPage.vue rather than reinvented:
 * `entered` flips on mount, and each `.reveal` carries its offset as a `--d`
 * custom property so the mobile media query can halve it. A stylesheet cannot
 * override an inline `transition-delay`, but it can re-derive from a custom
 * property — which is the whole reason for the indirection.
 */
const entered = ref(false)
const delay = (ms: number): CSSProperties => ({ '--d': `${ms}ms` })

/*
 * Sections below the fold reveal on scroll instead of on mount. One shared
 * observer rather than one per element: the page has ~20 targets and a single
 * observer with a 12% threshold is measurably cheaper than twenty.
 *
 * `once: true` semantics are achieved by unobserving on entry — a section that
 * has appeared stays visible when scrolled back past, which is what people
 * expect and what avoids a distracting re-animation on every pass.
 */
const scrollRoot = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null

onMounted(() => {
  requestAnimationFrame(() => {
    entered.value = true
  })

  /*
   * Reduced motion short-circuits the observer entirely rather than being
   * handled in CSS alone. The CSS switch already neutralises the transition,
   * but skipping the observer means no work is scheduled at all.
   */
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  const targets = scrollRoot.value?.querySelectorAll<HTMLElement>('.on-scroll') ?? []

  if (reduced) {
    targets.forEach((el) => el.classList.add('in-view'))
    return
  }

  observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return
        entry.target.classList.add('in-view')
        observer?.unobserve(entry.target)
      })
    },
    { threshold: 0.12, rootMargin: '0px 0px -40px 0px' },
  )

  targets.forEach((el) => observer?.observe(el))
})

onUnmounted(() => {
  observer?.disconnect()
  observer = null
})

/*
 * Content lives in data rather than repeated markup so the four role cards and
 * four steps stay structurally identical — a divergence between them would be a
 * bug, not a design choice.
 *
 * Accent order follows the project rule exactly: blue neutral, emerald good,
 * amber waiting, rose needs attention. Only those four hues appear on this page.
 */
type Role = {
  key: string
  title: string
  tagline: string
  points: string[]
  tile: string
  glyph: string
  dot: string
}

const roles: Role[] = [
  {
    key: 'student',
    title: 'Student',
    tagline: 'Log the day, write the journal.',
    points: [
      'QR or geofence clock-in from the mobile app',
      'Weekly journal against your program template',
      'Live hour count against the SIPP requirement',
    ],
    tile: 'bg-blue-50',
    glyph: 'text-blue-600',
    dot: 'bg-blue-600',
  },
  {
    key: 'supervisor',
    title: 'Company supervisor',
    tagline: 'Verify what actually happened.',
    points: [
      'Approve or return the day’s time record',
      'Comment on journal entries in context',
      'Submit periodic performance evaluations',
    ],
    tile: 'bg-emerald-50',
    glyph: 'text-emerald-600',
    dot: 'bg-emerald-500',
  },
  {
    key: 'coordinator',
    title: 'Coordinator',
    tagline: 'Watch the cohort, not the inbox.',
    points: [
      'Every enrolled student in one monitoring view',
      'Journal templates you define per program',
      'Flags for missing entries before they pile up',
    ],
    tile: 'bg-amber-50',
    glyph: 'text-amber-600',
    dot: 'bg-amber-500',
  },
  {
    key: 'admin',
    title: 'Administrator',
    tagline: 'Keep the roster true.',
    points: [
      'Accounts across 3 departments and 7 programs',
      'Company and supervisor records',
      'Soft deactivation — history is never destroyed',
    ],
    tile: 'bg-rose-50',
    glyph: 'text-rose-600',
    dot: 'bg-rose-500',
  },
]

const steps = [
  {
    num: '01',
    title: 'Coordinator enrols',
    body: 'The student is placed with a company and a supervisor, and the journal template for their program is attached.',
    tile: 'bg-blue-50',
    text: 'text-blue-600',
  },
  {
    num: '02',
    title: 'Student logs the day',
    body: 'Clock in by QR or geofence, then write the weekly journal against the fields the coordinator defined.',
    tile: 'bg-emerald-50',
    text: 'text-emerald-600',
  },
  {
    num: '03',
    title: 'Supervisor verifies',
    body: 'Time records are approved or returned, journal entries get comments, and evaluations are submitted on schedule.',
    tile: 'bg-amber-50',
    text: 'text-amber-600',
  },
  {
    num: '04',
    title: 'Coordinator clears',
    body: 'Hours, approvals and evaluations are already tallied, so completion is confirmed against real data.',
    tile: 'bg-rose-50',
    text: 'text-rose-600',
  },
]

/*
 * The four preview tiles are DECORATIVE MARKETING, not a dashboard. The project
 * rule that dashboards render only fields the API returns applies to the real
 * dashboards; this page has no authenticated session to read from. Keeping the
 * numbers as static strings here — rather than wiring an endpoint — is the
 * deliberate reading of that rule, and `aria-hidden` on the whole card means a
 * screen reader is never told these are its figures.
 *
 * 486 is not invented: it is the SIPP requirement the seeders write into
 * `batches.required_hours`, so the illustration matches a real cohort's
 * denominator rather than a made-up one.
 */
const previewStats = [
  { label: 'Hours logged', value: '312', caption: 'of 486 required', tint: 'bg-blue-50/60', text: 'text-blue-600' },
  { label: 'Journals approved', value: '48', caption: 'across 12 weeks', tint: 'bg-emerald-50/60', text: 'text-emerald-600' },
  { label: 'Awaiting review', value: '3', caption: 'with your supervisor', tint: 'bg-amber-50/60', text: 'text-amber-600' },
  { label: 'Missing entries', value: '1', caption: 'Week 9, Thursday', tint: 'bg-rose-50/60', text: 'text-rose-600' },
]

/*
 * The three role links target INDIVIDUAL role cards, not the section as a
 * whole. The Figma nav lists four links against only three content sections,
 * so pointing all of "For students/supervisors/coordinators" at `#roles` would
 * ship three controls that do the identical thing. Each role card carries its
 * own `id` (and `scroll-mt-24`) instead, which makes the distinction real —
 * and genuinely useful below `sm:`, where the cards stack into one column.
 *
 * Administrator is deliberately absent: it is the one role no visitor arrives
 * looking for, and a fourth link here would push the nav into the CTA.
 */
const navLinks = [
  { href: '#roles', label: 'Overview' },
  { href: '#role-student', label: 'For students' },
  { href: '#role-supervisor', label: 'For supervisors' },
  { href: '#role-coordinator', label: 'For coordinators' },
]
</script>

<template>
  <div ref="scrollRoot" class="min-h-dvh bg-white" :class="entered && 'entered'">
    <!--
      Skip link. First focusable element in the document, visually hidden until
      focused — a keyboard user should not have to tab through the whole nav to
      reach the page, and this page's nav is four links plus a button.
    -->
    <a
      href="#main"
      class="sr-only rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50"
    >
      Skip to content
    </a>

    <!-- ==================== NAV ==================== -->
    <header
      class="sticky top-0 z-40 border-b border-slate-200 bg-white/85 backdrop-blur-md"
    >
      <nav class="mx-auto flex h-19 w-full max-w-[1200px] items-center justify-between px-6 sm:px-8">
        <RouterLink to="/" class="flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-700">
          <img
            src="/images/mdc-logo.png"
            alt=""
            aria-hidden="true"
            class="h-10 w-10 rounded-full object-contain"
          />
          <span class="flex flex-col leading-none">
            <span class="text-lg font-bold tracking-tight text-slate-900">InternTrack</span>
            <span class="mt-0.5 text-[11px] font-medium text-slate-400">Mater Dei College</span>
          </span>
        </RouterLink>

        <div class="flex items-center gap-8">
          <ul class="hidden items-center gap-7 lg:flex">
            <li v-for="link in navLinks" :key="link.href">
              <a
                :href="link.href"
                class="rounded text-sm font-medium text-slate-600 transition-colors hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-700"
              >
                {{ link.label }}
              </a>
            </li>
          </ul>

          <!--
            The nav CTA and the hero CTA are the page's only filled blue buttons,
            and they never share a viewport: the nav is sticky but the hero's own
            button scrolls away long before the roles section arrives.
          -->
          <RouterLink
            to="/login"
            class="inline-flex min-h-11 items-center rounded-full bg-blue-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
          >
            Sign in
          </RouterLink>
        </div>
      </nav>
    </header>

    <main id="main">
      <!-- ==================== HERO ==================== -->
      <section
        class="bg-drift relative overflow-hidden bg-linear-to-br from-blue-900 via-blue-800 to-teal-500"
      >
        <!-- Ambient drift. Decorative only, fully stilled under prefers-reduced-motion. -->
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden">
          <span
            class="blob-a absolute -left-24 top-[-10%] h-[28rem] w-[28rem] rounded-full bg-linear-to-br from-teal-300 to-blue-400 opacity-25 blur-3xl"
          />
          <span
            class="blob-b absolute -right-32 bottom-[-15%] h-[32rem] w-[32rem] rounded-full bg-linear-to-tr from-sky-300 to-teal-200 opacity-25 blur-3xl"
          />
        </div>

        <div
          class="relative mx-auto flex w-full max-w-[1200px] flex-col items-center gap-14 px-6 py-20 sm:px-8 lg:flex-row lg:gap-16 lg:py-24"
        >
          <!-- Copy column -->
          <div class="w-full lg:w-[560px] lg:shrink-0">
            <div class="reveal" :style="delay(0)">
              <span
                class="inline-flex items-center rounded-full border border-white/30 bg-white/15 px-3.5 py-1.5 text-[11px] font-semibold tracking-wide text-teal-200 uppercase"
              >
                SIPP-aligned &middot; Mater Dei College
              </span>
            </div>

            <div class="reveal" :style="delay(80)">
              <h1
                class="mt-6 text-4xl leading-tight font-bold tracking-tight text-white sm:text-5xl sm:leading-[1.12] lg:text-[52px]"
              >
                Every OJT hour, journal, and evaluation &mdash; in one place.
              </h1>
            </div>

            <div class="reveal" :style="delay(160)">
              <p class="mt-6 max-w-[520px] text-base leading-relaxed text-blue-100 sm:text-[17px]">
                InternTrack digitizes the SIPP internship workflow end to end: daily time records with
                QR and geofence clock-in, weekly activity journals, supervisor review, and
                compliance-ready reports for every program.
              </p>
            </div>

            <div class="reveal mt-8 flex flex-wrap items-center gap-3.5" :style="delay(240)">
              <RouterLink
                to="/login"
                class="group inline-flex min-h-12 items-center gap-2 rounded-full bg-white px-7 text-[15px] font-semibold text-blue-900 shadow-lg transition-transform hover:-translate-y-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:hover:translate-y-0"
              >
                Sign in to InternTrack
                <svg
                  aria-hidden="true"
                  viewBox="0 0 24 24"
                  fill="none"
                  class="h-4 w-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
                >
                  <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </RouterLink>

              <a
                href="#how-it-works"
                class="inline-flex min-h-12 items-center rounded-full border border-white/45 bg-white/10 px-6 text-[15px] font-semibold text-white transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
              >
                See how it works
              </a>
            </div>

            <p class="reveal mt-7 text-[13px] font-medium text-blue-100" :style="delay(320)">
              3 departments &middot; 7 programs &middot; Tubigon, Bohol
            </p>
          </div>

          <!--
            Preview card. `aria-hidden` because it is an illustration of the
            product, not the product: a screen reader announcing "312 hours
            logged" to an unauthenticated visitor would be stating a fact about
            nobody.
          -->
          <div
            class="reveal w-full lg:flex-1"
            :style="delay(400)"
            aria-hidden="true"
          >
            <div class="rounded-3xl bg-white p-5 shadow-2xl">
              <div class="flex items-center justify-between">
                <span class="text-[10px] font-semibold tracking-wide text-slate-400 uppercase">
                  Student dashboard
                </span>
                <span class="text-[11px] font-medium text-slate-400">Week 12 of 16</span>
              </div>

              <div class="mt-4 flex items-center gap-3.5 rounded-xl bg-blue-50 p-4">
                <span
                  class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-900 text-sm font-semibold text-white"
                >
                  CA
                </span>
                <span class="flex flex-col">
                  <span class="text-[17px] font-semibold tracking-tight text-slate-900">Hello, Carlo</span>
                  <span class="mt-0.5 text-xs text-slate-600">BSIT &middot; Bohol Digital Solutions Inc.</span>
                </span>
              </div>

              <div class="mt-3 grid grid-cols-2 gap-3">
                <div
                  v-for="stat in previewStats"
                  :key="stat.label"
                  class="rounded-xl p-3.5 ring-1 ring-slate-200/70"
                  :class="stat.tint"
                >
                  <p class="text-[9px] font-semibold tracking-wide text-slate-400 uppercase">
                    {{ stat.label }}
                  </p>
                  <p class="mt-1 text-3xl font-semibold tracking-tight" :class="stat.text">
                    {{ stat.value }}
                  </p>
                  <p class="mt-0.5 text-[11px] text-slate-600">{{ stat.caption }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ==================== ROLES ==================== -->
      <section id="roles" class="scroll-mt-24 bg-white">
        <div class="mx-auto w-full max-w-[1200px] px-6 py-20 sm:px-8 lg:py-24">
          <div class="on-scroll mx-auto max-w-[760px] text-center">
            <p class="text-[11px] font-semibold tracking-wide text-slate-400 uppercase">
              One system, four vantage points
            </p>
            <h2 class="mt-3.5 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
              Built around how an internship actually runs
            </h2>
            <p class="mx-auto mt-3.5 max-w-[680px] text-base leading-relaxed text-slate-600">
              A student logs the day, a company supervisor verifies it, a coordinator monitors the
              cohort, and an administrator keeps the roster clean. InternTrack gives each of them
              only what they need.
            </p>
          </div>

          <!--
            `items-stretch` plus `h-full` on the card is what makes a row equal
            height despite the third card carrying a longer bullet — the same
            pattern the dashboards' panel rows use.
          -->
          <ul class="mt-12 grid items-stretch gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <li
              v-for="(role, i) in roles"
              :id="`role-${role.key}`"
              :key="role.key"
              class="on-scroll scroll-mt-24"
              :style="delay(i * 70)"
            >
              <div
                class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70"
              >
                <span
                  class="flex h-10 w-10 items-center justify-center rounded-lg ring-1 ring-slate-200/70"
                  :class="role.tile"
                >
                  <svg
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="h-5 w-5"
                    :class="role.glyph"
                  >
                    <template v-if="role.key === 'student'">
                      <path d="M22 10 12 5 2 10l10 5 10-5Z" />
                      <path d="M6 12v5c0 1 2.7 3 6 3s6-2 6-3v-5" />
                    </template>
                    <template v-else-if="role.key === 'supervisor'">
                      <rect x="8" y="2" width="8" height="4" rx="1" />
                      <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                      <path d="m9 14 2 2 4-4" />
                    </template>
                    <template v-else-if="role.key === 'coordinator'">
                      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                      <circle cx="9" cy="7" r="4" />
                      <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                      <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </template>
                    <template v-else>
                      <path
                        d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z"
                      />
                    </template>
                  </svg>
                </span>

                <h3 class="mt-3.5 text-[17px] font-semibold tracking-tight text-slate-900">
                  {{ role.title }}
                </h3>
                <p class="mt-1.5 text-sm text-slate-600">{{ role.tagline }}</p>

                <ul class="mt-3.5 flex flex-col gap-2.5">
                  <li v-for="point in role.points" :key="point" class="flex gap-2.5">
                    <span
                      aria-hidden="true"
                      class="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full"
                      :class="role.dot"
                    />
                    <span class="text-[13px] leading-5 text-slate-600">{{ point }}</span>
                  </li>
                </ul>
              </div>
            </li>
          </ul>
        </div>
      </section>

      <!-- ==================== CAPABILITIES ==================== -->
      <section id="capabilities" class="scroll-mt-24 bg-slate-50">
        <div
          class="mx-auto flex w-full max-w-[1200px] flex-col items-center gap-14 px-6 py-20 sm:px-8 lg:flex-row lg:gap-18 lg:py-24"
        >
          <div class="on-scroll w-full lg:w-[520px] lg:shrink-0">
            <p class="text-[11px] font-semibold tracking-wide text-slate-400 uppercase">
              What it replaces
            </p>
            <h2 class="mt-3.5 text-3xl font-bold tracking-tight text-slate-900 sm:text-[34px] sm:leading-[42px]">
              Paper logbooks, signed by hand, checked once a semester
            </h2>
            <p class="mt-3.5 max-w-[480px] text-base leading-relaxed text-slate-600">
              Attendance that can be verified at the moment it happens, and journals a coordinator
              can read the same week they are written.
            </p>

            <ul class="mt-8 flex flex-col gap-5.5">
              <li class="flex gap-4">
                <span
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-slate-200/70"
                >
                  <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-blue-600">
                    <rect x="3" y="3" width="7" height="7" rx="1" />
                    <rect x="14" y="3" width="7" height="7" rx="1" />
                    <rect x="3" y="14" width="7" height="7" rx="1" />
                    <path d="M14 14h3v3" />
                    <path d="M21 21h-4" />
                  </svg>
                </span>
                <span>
                  <h3 class="text-base font-semibold tracking-tight text-slate-900">Verified clock-in</h3>
                  <p class="mt-1 text-sm leading-[21px] text-slate-600">
                    Scan the company QR in the app, or clock in inside the geofence. Both stamp a
                    record nobody can back-date.
                  </p>
                </span>
              </li>

              <li class="flex gap-4">
                <span
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-slate-200/70"
                >
                  <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-emerald-600">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" />
                  </svg>
                </span>
                <span>
                  <h3 class="text-base font-semibold tracking-tight text-slate-900">Journals on your template</h3>
                  <p class="mt-1 text-sm leading-[21px] text-slate-600">
                    Coordinators define the fields per program. Students fill them weekly;
                    supervisors comment in place.
                  </p>
                </span>
              </li>

              <li class="flex gap-4">
                <span
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-slate-200/70"
                >
                  <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-amber-600">
                    <path d="M3 3v18h18" />
                    <path d="M7 16v-4" />
                    <path d="M12 16V9" />
                    <path d="M17 16v-9" />
                  </svg>
                </span>
                <span>
                  <h3 class="text-base font-semibold tracking-tight text-slate-900">Reports without the scramble</h3>
                  <p class="mt-1 text-sm leading-[21px] text-slate-600">
                    Hours, completion and evaluation data are already structured, so the end-of-term
                    report is a filter, not a rebuild.
                  </p>
                </span>
              </li>
            </ul>
          </div>

          <!-- Illustrative DTR panel. Decorative for the same reason as the hero card. -->
          <div class="on-scroll w-full lg:flex-1" :style="delay(120)" aria-hidden="true">
            <div class="rounded-2xl bg-white p-5 shadow-lg ring-1 ring-slate-200/70">
              <div class="flex items-center justify-between">
                <span class="flex flex-col">
                  <span class="text-base font-semibold tracking-tight text-slate-900">Daily Time Record</span>
                  <span class="mt-0.5 text-xs text-slate-400">Thursday, 27 August</span>
                </span>
                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-600">
                  Verified
                </span>
              </div>

              <!-- Geofence illustration. Pure CSS; no map tile is loaded. -->
              <div class="relative mt-4 h-[150px] overflow-hidden rounded-xl bg-slate-50 ring-1 ring-slate-200/70">
                <span
                  class="absolute left-1/2 top-2 h-26 w-26 -translate-x-1/2 rounded-full border-[1.5px] border-dashed border-teal-500/50 bg-teal-500/10"
                />
                <span
                  class="absolute left-1/2 top-[53px] h-3.5 w-3.5 -translate-x-1/2 rounded-full border-[3px] border-white bg-blue-600"
                />
                <span class="absolute bottom-2.5 left-3.5 text-[11px] font-medium text-slate-600">
                  Inside the geofence &middot; 42 m from Bohol Digital Solutions Inc.
                </span>
              </div>

              <div class="mt-4 flex flex-col gap-3">
                <div class="flex items-center justify-between rounded-xl px-3.5 py-3 ring-1 ring-slate-200/70">
                  <span class="flex flex-col">
                    <span class="text-xs font-medium text-slate-400">Time in</span>
                    <span class="mt-0.5 text-lg font-semibold tracking-tight text-slate-900">08:02 AM</span>
                  </span>
                  <span class="rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-600">
                    QR scan
                  </span>
                </div>

                <div class="flex items-center justify-between rounded-xl px-3.5 py-3 ring-1 ring-slate-200/70">
                  <span class="flex flex-col">
                    <span class="text-xs font-medium text-slate-400">Time out</span>
                    <span class="mt-0.5 text-lg font-semibold tracking-tight text-slate-900">05:04 PM</span>
                  </span>
                  <span class="rounded-full bg-teal-50 px-2.5 py-1 text-[11px] font-semibold text-teal-600">
                    Geofence
                  </span>
                </div>
              </div>

              <div class="mt-4 flex items-center justify-between">
                <span class="text-[13px] font-medium text-slate-600">Recorded today</span>
                <span class="text-[15px] font-semibold text-slate-900">8.03 hrs</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ==================== HOW IT WORKS ==================== -->
      <section id="how-it-works" class="scroll-mt-24 bg-white">
        <div class="mx-auto w-full max-w-[1200px] px-6 py-20 sm:px-8 lg:py-24">
          <div class="on-scroll mx-auto max-w-[760px] text-center">
            <p class="text-[11px] font-semibold tracking-wide text-slate-400 uppercase">
              From enrolment to clearance
            </p>
            <h2 class="mt-3.5 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
              Four steps, one record that follows the student
            </h2>
          </div>

          <!--
            An ordered list, not a div grid: the sequence is the content. The
            numerals are decorative duplicates of the list order, so they carry
            `aria-hidden` and a screen reader hears "1." once, not "01, one".
          -->
          <ol class="mt-12 grid gap-8 sm:grid-cols-2 xl:grid-cols-4 xl:gap-7">
            <li
              v-for="(step, i) in steps"
              :key="step.num"
              class="on-scroll"
              :style="delay(i * 70)"
            >
              <span
                aria-hidden="true"
                class="inline-flex items-center rounded-full px-3.5 py-2 text-[13px] font-bold"
                :class="[step.tile, step.text]"
              >
                {{ step.num }}
              </span>
              <h3 class="mt-3 text-[17px] font-semibold tracking-tight text-slate-900">
                {{ step.title }}
              </h3>
              <p class="mt-1.5 text-sm leading-[21px] text-slate-600">{{ step.body }}</p>
            </li>
          </ol>
        </div>
      </section>

      <!-- ==================== CLOSING CTA ==================== -->
      <section class="bg-white">
        <div class="mx-auto w-full max-w-[1200px] px-6 pb-20 sm:px-8 lg:pb-24">
          <div
            class="on-scroll bg-drift overflow-hidden rounded-2xl bg-linear-to-br from-blue-900 via-blue-800 to-teal-500 px-6 py-14 text-center sm:px-16 sm:py-16"
          >
            <h2 class="text-3xl font-bold tracking-tight text-white sm:text-[34px]">
              Ready to open your dashboard?
            </h2>
            <p class="mx-auto mt-4 max-w-[560px] text-base leading-relaxed text-blue-100">
              Accounts are issued by your coordinator. Sign in with the username you were given.
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-center gap-3.5">
              <RouterLink
                to="/login"
                class="group inline-flex min-h-12 items-center gap-2 rounded-full bg-white px-7 text-[15px] font-semibold text-blue-900 shadow-lg transition-transform hover:-translate-y-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:hover:translate-y-0"
              >
                Sign in to InternTrack
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-4 w-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none">
                  <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </RouterLink>

              <RouterLink
                to="/forgot-password"
                class="inline-flex min-h-12 items-center rounded-full border border-white/45 bg-white/10 px-6 text-[15px] font-semibold text-white transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
              >
                Forgot your password?
              </RouterLink>
            </div>
          </div>
        </div>
      </section>
    </main>

    <!-- ==================== FOOTER ==================== -->
    <footer class="border-t border-slate-200 bg-slate-50">
      <div
        class="mx-auto flex w-full max-w-[1200px] flex-col gap-5 px-6 py-10 sm:px-8 md:flex-row md:items-center md:justify-between"
      >
        <div class="flex items-center gap-3">
          <img
            src="/images/mdc-logo.png"
            alt="Mater Dei College seal"
            class="h-8 w-8 rounded-full object-contain"
          />
          <span class="flex flex-col">
            <span class="text-sm font-semibold tracking-tight text-slate-900">InternTrack</span>
            <span class="mt-0.5 text-[11px] text-slate-400">
              Internship Journal and Progress Monitoring System
            </span>
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-x-7 gap-y-2">
          <span class="text-xs font-medium text-slate-600">
            Mater Dei College &middot; Tubigon, Bohol
          </span>
          <RouterLink
            to="/login"
            class="rounded text-xs font-medium text-slate-600 transition-colors hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-700"
          >
            Sign in
          </RouterLink>
        </div>
      </div>
    </footer>
  </div>
</template>

<style scoped>
/* ---- Entrance stagger --------------------------------------------------- */

/*
 * Identical mechanism to LoginPage.vue. The offset arrives as an inline `--d`
 * custom property rather than an inline `transition-delay`, which is what lets
 * the mobile query below halve it — a stylesheet cannot override an inline
 * declaration, but it can re-derive from a custom property.
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

/* ---- Scroll reveal ------------------------------------------------------ */

/*
 * Same visual grammar as `.reveal`, but gated by `.in-view` from the
 * IntersectionObserver rather than by `.entered` on mount. A shorter travel
 * (12px) because these arrive already near their resting position.
 */
.on-scroll {
  opacity: 0;
  transform: translateY(12px);
  transition:
    opacity 550ms cubic-bezier(0.22, 1, 0.36, 1),
    transform 550ms cubic-bezier(0.22, 1, 0.36, 1);
  transition-delay: var(--d, 0ms);
}

.on-scroll.in-view {
  opacity: 1;
  transform: translateY(0);
}

/* ---- Living background --------------------------------------------------- */

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

/* ---- Below lg: halve the stagger ---------------------------------------- */

@media (max-width: 1023px) {
  .reveal,
  .on-scroll {
    transition-delay: calc(var(--d, 0ms) / 2);
  }
}

/* ---- One shared reduced-motion switch ------------------------------------ */

@media (prefers-reduced-motion: reduce) {
  .bg-drift,
  .blob-a,
  .blob-b {
    animation: none;
  }

  .reveal,
  .on-scroll {
    opacity: 1;
    transform: none;
    transition: none;
  }
}
</style>

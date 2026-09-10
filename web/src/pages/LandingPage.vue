<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

/*
 * The campus photo is imported as a module so Vite fingerprints and copies it.
 * A `url()` written inside the stylesheet would resolve relative to the built
 * CSS and is not rewritten the same way, so the binding stays in the template.
 */
import campus from '@/assets/images/mdc-campus.jpg'

const menuOpen = ref(false)

/*
 * The header has NO background band. It floats on the page, which means its own
 * colours have to answer to whatever is currently underneath it — white type
 * over the hero photograph and the dark bands, ink over the light ones.
 *
 * `navOnLight` is that switch. There is no scroll listener any more: the old one
 * existed solely to drive the removed `is-solid` band, and this is a question
 * about which BAND is under the header, not about how far the page has moved.
 */
const HEADER_ZONE = 90
const navOnLight = ref(false)
let bandObserver: IntersectionObserver | null = null

const navLinks = [
  { href: '#how', label: 'How it works' },
  { href: '#roles', label: 'Who uses it' },
  { href: '#record', label: 'The record' },
  { href: '#more', label: 'Everything else' },
]

/* ---------- D. Which section the reader is actually in ---------- */

/*
 * DOCUMENT order, which is NOT the nav's order — `#roles` sits above `#how` on
 * the page while the nav lists "How it works" first. The observer below breaks
 * ties by taking the first entry in this array, so it has to describe where the
 * sections actually are; taking the nav's order would light the lower link
 * whenever the two overlap.
 */
const SECTION_IDS = ['roles', 'how', 'record', 'more']
const activeSection = ref<string | null>(null)
let sectionObserver: IntersectionObserver | null = null

const prefersReducedMotion = (): boolean =>
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

/*
 * E. The href stays on every anchor, so the links still work with no JS and the
 * address bar still updates — this only replaces the jump with a scroll, and
 * honours a reduced-motion preference by falling back to the jump.
 */
const scrollToAnchor = (event: MouseEvent, href: string): void => {
  const target = document.querySelector(href)
  menuOpen.value = false
  if (!target) return

  event.preventDefault()
  target.scrollIntoView({
    behavior: prefersReducedMotion() ? 'auto' : 'smooth',
    block: 'start',
  })
  history.pushState(null, '', href)
}

onMounted(() => {
  handleScroll()
  window.addEventListener('scroll', handleScroll, { passive: true })

  /*
   * A Set plus a document-order lookup rather than "last entry wins": the
   * margins below leave a thin band in which two sections can be intersecting
   * at once, and picking the first in order is what keeps exactly one link lit
   * instead of letting them flicker against each other.
   */
  const visible = new Set<string>()
  sectionObserver = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) visible.add(entry.target.id)
        else visible.delete(entry.target.id)
      }
      activeSection.value = SECTION_IDS.find((id) => visible.has(id)) ?? null
    },
    { rootMargin: '-30% 0px -60% 0px' },
  )

  for (const id of SECTION_IDS) {
    const el = document.getElementById(id)
    if (el) sectionObserver.observe(el)
  }
})

onUnmounted(() => {
  window.removeEventListener('scroll', handleScroll)
  sectionObserver?.disconnect()
  sectionObserver = null
})

const facts = [
  { label: 'Departments', value: '3' },
  { label: 'Programs', value: '7' },
  { label: 'Roles', value: '4' },
]

/* ---------- B1. The journal mock: submitting locks the entry ---------- */

const journalLocked = ref(false)

/* ---------- B2. The time-record mock: hours come from a scan ---------- */

type DtrState = 'out' | 'in' | 'closed'

const dtrState = ref<DtrState>('out')

/*
 * 486 is the SIPP requirement the seeders write into `batches.required_hours`
 * — the one real figure on this page. The other two numbers are the sample
 * day the card logs.
 */
const REQUIRED_HOURS = 486
const LOGGED_MINUTES = 146 * 60
const SESSION_MINUTES = 8 * 60 + 33

const dtrHours = computed(() => {
  const total = LOGGED_MINUTES + (dtrState.value === 'closed' ? SESSION_MINUTES : 0)
  return `${Math.floor(total / 60)} / ${REQUIRED_HOURS} hrs`
})

const dtrPill = computed(() => {
  if (dtrState.value === 'in') return { label: 'Clocked in', tone: 'pill-green' }
  if (dtrState.value === 'closed') return { label: 'Day closed', tone: 'pill-grey' }
  return { label: 'Not clocked in', tone: 'pill-grey' }
})

const dtrTuesday = computed(() => {
  if (dtrState.value === 'in') return { span: '7:58 — …', total: '—' }
  if (dtrState.value === 'closed') return { span: '7:58 — 16:31', total: '8h 33m' }
  return { span: '—', total: '—' }
})

const dtrNote = computed(() =>
  dtrState.value === 'out' ? 'Scan the code at your company' : 'Stamped at the host company',
)

const dtrAction = computed(() => {
  if (dtrState.value === 'out') return 'Clock in'
  if (dtrState.value === 'in') return 'Clock out'
  return 'Undo'
})

const cycleDtr = (): void => {
  dtrState.value =
    dtrState.value === 'out' ? 'in' : dtrState.value === 'in' ? 'closed' : 'out'
}

/* ---------- B3. The week mock: it bundles, then it is fixed ---------- */

const DAY_NAMES = [
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
]
const DAY_INITIALS = ['M', 'T', 'W', 'T', 'F', 'S', 'S']

const weekDays = ref([true, true, true, true, true, false, false])
const weekBundled = ref(false)

const toggleDay = (index: number): void => {
  if (weekBundled.value) return
  weekDays.value[index] = !weekDays.value[index]
}

/* ---------- C. Roles, one at a time ---------- */

const roles = [
  {
    id: 'students',
    name: 'Students',
    lead: 'Write the day, see the week.',
    body: 'Clock in, write one journal per day, and watch your hours add up. Submitted entries lock, so nobody can accuse you of backfilling a week.',
    points: [
      'Daily journal with accomplishments',
      'Calendar of what is missing',
      'Own time record and totals',
    ],
  },
  {
    id: 'supervisors',
    name: 'Supervisors',
    lead: 'Sign off without the paperwork.',
    body: 'Read your interns’ entries as one notebook instead of loose sheets, and return anything that needs more detail before it counts.',
    points: [
      'Company-scoped intern list',
      'Approve or return an entry',
      'Attendance at a glance',
    ],
  },
  {
    id: 'coordinators',
    name: 'Coordinators',
    lead: 'Watch the whole cohort.',
    body: 'Every batch, every program, one screen. Accept information sheets, place students with partner companies, and pull the reports the college asks for.',
    points: [
      'Enrollment and batch control',
      'Weekly and time-log summaries',
      'Annual SIPP and HTE reports',
    ],
  },
  {
    id: 'admins',
    name: 'Admins',
    lead: 'Keep the record straight.',
    body: 'Departments, programs, accounts, and an audit trail of who changed what. Deactivation is reversible; history is never quietly deleted.',
    points: ['User and program setup', 'Audit logs', 'System settings'],
  },
]

const activeRole = ref(0)

/*
 * Plain array, not a `ref` — these hold DOM nodes only so a keyboard move can
 * put focus on the tab it just activated, and nothing renders from them.
 */
const tabRefs: HTMLButtonElement[] = []

const setTabRef = (el: unknown, index: number): void => {
  if (el instanceof HTMLButtonElement) tabRefs[index] = el
}

/* Roving tabindex: arrows move AND activate, Home/End jump to the ends. */
const onTabKeydown = (event: KeyboardEvent, index: number): void => {
  const last = roles.length - 1
  let next: number | null = null

  if (event.key === 'ArrowRight') next = index === last ? 0 : index + 1
  else if (event.key === 'ArrowLeft') next = index === 0 ? last : index - 1
  else if (event.key === 'Home') next = 0
  else if (event.key === 'End') next = last

  if (next === null) return

  event.preventDefault()
  activeRole.value = next
  tabRefs[next]?.focus()
}

const steps = [
  {
    title: 'Submit your information sheet',
    body: 'You register yourself and fill in the sheet. Until your coordinator accepts it, that is the only page the system will let you open.',
  },
  {
    title: 'Coordinator accepts and enrolls you',
    body: 'Acceptance places you in a batch, assigns your company and supervisor, and issues your credentials.',
  },
  {
    title: 'Clock in at the company',
    body: 'Scan the printed code at your host company. The record is stamped where and when it happened, not from memory at the end of the week.',
  },
  {
    title: 'Write the day before it closes',
    body: 'One entry per day, with the tasks you actually did. Once you submit it, it locks.',
  },
  {
    title: 'The week bundles itself on Monday',
    body: 'Monday at midnight the finished week is gathered into a weekly journal and time-log summary, ready for review and signature.',
  },
]

const guarantees = [
  { term: 'Stamped clock-in', detail: 'The time and place come from the scan, not from a form filled in later.' },
  { term: 'Locked entries', detail: 'A submitted day is fixed. Changes go through a coordinator and leave a trace.' },
  { term: 'Scheduled bundling', detail: 'Weeks close on a schedule every Monday, whether or not anyone asks.' },
  { term: 'Audit trail', detail: 'Who changed what, and when. Accounts deactivate rather than disappear.' },
]

const extras = [
  { term: 'Student information sheets', detail: 'The intake form a coordinator reviews before placing you.' },
  { term: 'Partner companies', detail: 'The host establishments a department places its interns with.' },
  { term: 'Weekly time-log summaries', detail: 'The signed weekly form, assembled from the days you logged.' },
  { term: 'Annual SIPP report', detail: 'The yearly submission, built from entries already in the system.' },
  { term: 'Exit interviews', detail: 'The closing form a student files at the end of a placement.' },
  { term: 'Audit logs', detail: 'A running record of every consequential change to the data.' },
]
</script>

<template>
  <div class="page">
    <a class="skip" href="#main">Skip to content</a>

    <header class="nav" :class="{ 'is-solid': scrolled || menuOpen }">
      <div class="nav-inner">
        <RouterLink to="/" class="brand">
          <!--
            The campus chapel's twin-pitch roofline: a peaked centre flanked by
            two lower wings, traced from the building in the hero photograph.
            Deliberately NOT the college seal — that is a formal mark with three
            concentric text rings and is illegible below about 80px.
          -->
          <svg
            class="mark"
            width="30"
            height="30"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path d="M1.5 14 7 9l5-5.5L17 9l5.5 5" />
            <path d="M5 18.5 9.2 14.6 12 11.7l2.8 2.9 4.2 3.9" />
          </svg>
          <span class="brand-text">
            <span class="brand-name">InternTrack</span>
            <span class="brand-sub">Mater Dei College</span>
          </span>
        </RouterLink>

        <nav class="nav-links" aria-label="Primary">
          <a
            v-for="link in navLinks"
            :key="link.href"
            :href="link.href"
            :class="{ 'is-active': `#${activeSection}` === link.href }"
            :aria-current="`#${activeSection}` === link.href ? 'true' : undefined"
            @click="scrollToAnchor($event, link.href)"
          >{{ link.label }}</a>
          <RouterLink to="/login" class="btn btn-gold btn-sm">Sign in</RouterLink>
        </nav>

        <button
          type="button"
          class="nav-toggle"
          :aria-expanded="menuOpen"
          aria-controls="nav-panel"
          @click="menuOpen = !menuOpen"
        >
          {{ menuOpen ? 'Close' : 'Menu' }}
        </button>
      </div>

      <div v-show="menuOpen" id="nav-panel" class="nav-panel">
        <a
          v-for="link in navLinks"
          :key="link.href"
          :href="link.href"
          @click="scrollToAnchor($event, link.href)"
        >{{ link.label }}</a>
        <RouterLink to="/login" class="btn btn-gold" @click="menuOpen = false">Sign in</RouterLink>
      </div>

      <!--
        Decoration, so `aria-hidden` — the nav's own active-link state already
        carries "where am I in this page" accessibly, and a screen reader has no
        use for a second, wordless copy of it.
      -->
      <div class="rail" aria-hidden="true">
        <span
          v-for="segment in RAIL_SEGMENTS"
          :key="segment"
          class="rail-seg"
          :class="{ 'is-filled': segment <= filledSegments }"
        />
      </div>
    </header>

    <main id="main">
      <!-- 2 — Hero -->
      <section class="hero">
        <div
          class="hero-photo"
          role="img"
          aria-label="The Mater Dei College campus in Tubigon, Bohol"
          :style="{ backgroundImage: `url(${campus})` }"
        />
        <div class="hero-scrim" aria-hidden="true" />
        <div class="shell hero-inner">
          <div class="hero-copy">
            <p class="eyebrow">Internship journal and progress monitoring</p>
            <h1 class="display hero-title">
              Every OJT day,<br />written down and accounted for.
            </h1>
            <p class="hero-lede">
              Students log the work they did. Supervisors sign it. Coordinators see the whole
              cohort without chasing a single sheet of paper.
            </p>
            <div class="hero-actions">
              <!--
                The hero's only action. Sign-in lives in the sticky nav directly
                above it, so a second copy here was the same call twice in one
                viewport.
              -->
              <a href="#how" class="btn btn-gold" @click="scrollToAnchor($event, '#how')">
                See how it works
              </a>
            </div>
          </div>
        </div>

        <!--
          The stat plinth is full-bleed and pinned to the foot of the hero. It is
          the page's one signature element, and it also solves a contrast problem:
          these figures previously floated over sunlit grass with nothing behind
          them.
        -->
        <div class="hero-plinth">
          <dl class="shell facts">
            <div v-for="fact in facts" :key="fact.label" class="fact">
              <dt>{{ fact.label }}</dt>
              <dd class="display">{{ fact.value }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <!-- 3 — The argument -->
      <section class="band band-paper">
        <div class="shell">
          <div class="split">
            <h2 class="display h2">
              The logbook was never the problem.<br />Finding it was.
            </h2>
            <div class="split-body">
              <p>
                A narrative report copied out the night before the deadline is not a record of
                anything. Neither is a time sheet signed in one sitting at the end of the
                semester.
              </p>
              <p>
                InternTrack moves the writing to the day it happened and keeps it there. Entries
                lock on submission, hours come from a stamped clock-in, and the week assembles
                itself so nobody has to reconstruct it later.
              </p>
            </div>
          </div>

          <!--
            The three mocks are operable, not decorative: each one performs the
            rule its heading states. They are therefore NOT `aria-hidden` — they
            hold real controls and have to be reachable by keyboard.
          -->
          <div class="cards">
            <article class="card">
              <div class="mock">
                <div class="mock-head">
                  <span class="mock-title">Tuesday, 8 September</span>
                  <span class="pill" :class="journalLocked ? 'pill-gold' : 'pill-blue'">
                    {{ journalLocked ? 'Locked' : 'Open' }}
                  </span>
                </div>
                <p class="mock-label">Daily accomplishment</p>
                <div class="bars">
                  <span class="bar" :class="{ 'is-locked': journalLocked }" style="width: 92%" />
                  <span class="bar" :class="{ 'is-locked': journalLocked }" style="width: 78%" />
                  <span class="bar" :class="{ 'is-locked': journalLocked }" style="width: 61%" />
                </div>
                <div class="mock-foot">
                  <span>
                    {{ journalLocked ? 'Locked Tuesday, 8 September' : 'Submitting locks this entry' }}
                  </span>
                  <button
                    v-if="!journalLocked"
                    type="button"
                    class="mock-btn"
                    aria-label="Submit the sample journal entry"
                    @click="journalLocked = true"
                  >
                    Submit
                  </button>
                  <button
                    v-else
                    type="button"
                    class="mock-undo"
                    aria-label="Undo the sample journal lock"
                    @click="journalLocked = false"
                  >
                    Undo
                  </button>
                </div>
              </div>
              <h3 class="card-title">One entry per day, then it locks</h3>
              <p class="card-body">
                The form follows your program&rsquo;s own journal template. Once you submit, the
                entry is part of the record &mdash; corrections go through your coordinator, not a
                rewrite.
              </p>
            </article>

            <article class="card">
              <div class="mock">
                <div class="mock-head">
                  <span class="mock-title">Daily time record</span>
                  <span class="pill" :class="dtrPill.tone">{{ dtrPill.label }}</span>
                </div>
                <div class="rows">
                  <div class="row">
                    <span class="row-day">Mon 07</span>
                    <span class="row-span">8:02 &mdash; 17:04</span>
                    <span class="row-total">8h 32m</span>
                  </div>
                  <div class="row">
                    <span class="row-day">Tue 08</span>
                    <span class="row-span">{{ dtrTuesday.span }}</span>
                    <span class="row-total">{{ dtrTuesday.total }}</span>
                  </div>
                </div>
                <div class="mock-foot">
                  <span>{{ dtrNote }}</span>
                  <span class="mock-total">{{ dtrHours }}</span>
                  <button
                    type="button"
                    :class="dtrState === 'closed' ? 'mock-undo' : 'mock-btn'"
                    :aria-label="`${dtrAction} on the sample time record`"
                    @click="cycleDtr"
                  >
                    {{ dtrAction }}
                  </button>
                </div>
              </div>
              <h3 class="card-title">Hours from a scan, not a memory</h3>
              <p class="card-body">
                A printed code at the company starts and ends the day. Totals build themselves,
                and a student always knows how many hours are left.
              </p>
            </article>

            <article class="card">
              <div class="mock">
                <div class="mock-head">
                  <span class="mock-title">Week 6 &middot; 1&ndash;7 September</span>
                  <span class="pill" :class="weekBundled ? 'pill-gold' : 'pill-blue'">
                    {{ weekBundled ? 'Bundled' : 'Open' }}
                  </span>
                </div>
                <div class="week">
                  <button
                    v-for="(logged, index) in weekDays"
                    :key="index"
                    type="button"
                    class="day"
                    :class="{ 'is-filled': logged }"
                    :disabled="weekBundled"
                    :aria-label="`${DAY_NAMES[index]} — ${logged ? 'logged' : 'no entry'}`"
                    :aria-pressed="logged"
                    @click="toggleDay(index)"
                  >
                    {{ DAY_INITIALS[index] }}
                  </button>
                </div>
                <div class="mock-foot">
                  <span>{{ weekBundled ? 'Bundled Monday, 00:00' : 'Bundles Monday, 00:00' }}</span>
                  <button
                    v-if="!weekBundled"
                    type="button"
                    class="mock-btn"
                    aria-label="Bundle the sample week"
                    @click="weekBundled = true"
                  >
                    Bundle week
                  </button>
                  <button
                    v-else
                    type="button"
                    class="mock-undo"
                    aria-label="Undo the sample week bundle"
                    @click="weekBundled = false"
                  >
                    Undo
                  </button>
                </div>
              </div>
              <h3 class="card-title">The week closes on its own</h3>
              <p class="card-body">
                Every Monday midnight the finished week is gathered into a weekly journal and
                time-log summary and queued for review. No reminder, no compiling.
              </p>
            </article>
          </div>
        </div>
      </section>

      <!-- 4 — Roles -->
      <section id="roles" class="band band-ink">
        <div class="shell">
          <h2 class="display h2 h2-wide">
            Four people look at the same OJT. They should not need four systems.
          </h2>

          <div class="tablist" role="tablist" aria-label="Choose a role">
            <button
              v-for="(role, index) in roles"
              :key="role.id"
              :ref="(el) => setTabRef(el, index)"
              type="button"
              class="tab"
              role="tab"
              :id="`role-tab-${role.id}`"
              :aria-controls="`role-panel-${role.id}`"
              :aria-selected="activeRole === index"
              :tabindex="activeRole === index ? 0 : -1"
              @click="activeRole = index"
              @keydown="onTabKeydown($event, index)"
            >
              {{ role.name }}
            </button>
          </div>

          <div
            v-for="(role, index) in roles"
            v-show="activeRole === index"
            :key="role.id"
            class="role-panel"
            role="tabpanel"
            :id="`role-panel-${role.id}`"
            :aria-labelledby="`role-tab-${role.id}`"
            :tabindex="0"
          >
            <div>
              <h3 class="display role-name">{{ role.name }}</h3>
              <p class="role-lead">{{ role.lead }}</p>
              <p class="role-body">{{ role.body }}</p>
            </div>
            <ul class="ticks">
              <li v-for="point in role.points" :key="point">{{ point }}</li>
            </ul>
          </div>
        </div>
      </section>

      <!--
        The statement band. Its job is the PAUSE — the page ran
        headline-body three times over with no change of tempo, and this is the
        break in it. No heading, no button, no card: anything else added here
        would take the rest away.
      -->
      <section class="statement">
        <div class="shell">
          <p class="display statement-line">
            One day, one entry, one <span class="statement-accent">signature</span>.
          </p>
          <p class="statement-sub">
            That is the whole discipline. The system just makes it hold.
          </p>
        </div>
      </section>

      <!-- 5 — How it works -->
      <section id="how" class="band band-paper">
        <div class="shell">
          <h2 class="display h2">From information sheet to signed week</h2>
          <ol class="steps">
            <li v-for="(step, index) in steps" :key="step.title" class="step">
              <span class="display step-num">{{ index + 1 }}</span>
              <div class="step-copy">
                <h3 class="step-title">{{ step.title }}</h3>
                <p class="step-body">{{ step.body }}</p>
              </div>
            </li>
          </ol>
        </div>
      </section>

      <!-- 6 — The record -->
      <section id="record" class="band band-navy">
        <div class="shell">
          <div class="split split-record">
            <div>
              <h2 class="display h2">Nothing in the record depends on anyone remembering</h2>
              <p class="record-lede">
                A day is stamped when it is worked. An entry is fixed when it is submitted. A
                week is bundled on a schedule, not on request. What a coordinator opens in March
                is what actually happened in September.
              </p>
            </div>
            <dl class="guarantees">
              <div v-for="item in guarantees" :key="item.term" class="guarantee">
                <dt>{{ item.term }}</dt>
                <dd>{{ item.detail }}</dd>
              </div>
            </dl>
          </div>
        </div>
      </section>

      <!-- 7 — Everything else -->
      <section id="more" class="band band-paper">
        <div class="shell">
          <h2 class="display h2">And the rest of the paperwork</h2>
          <dl class="extras">
            <div v-for="item in extras" :key="item.term" class="extra">
              <dt>{{ item.term }}</dt>
              <dd>{{ item.detail }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <!-- 8 — Closing -->
      <section class="closing">
        <div
          class="closing-photo"
          role="img"
          aria-label="The Mater Dei College campus in Tubigon, Bohol"
          :style="{ backgroundImage: `url(${campus})` }"
        />
        <div class="closing-scrim" aria-hidden="true" />
        <!--
          An identity plate, not a call to action. Sign-in is permanently one
          click away in the fixed header, so a second copy down here was pure
          redundancy.
        -->
        <div class="shell closing-inner">
          <h2 class="display h2">Built at Mater Dei College, for its own interns.</h2>
          <p class="closing-sub">Tubigon, Bohol &middot; CAST &middot; CABM-B &middot; CABM-H</p>
        </div>
      </section>
    </main>

    <!-- 9 — Footer -->
    <footer class="footer">
      <div class="shell footer-grid">
        <div class="footer-brand">
          <span class="display footer-name">InternTrack</span>
          <p>Internship journal and progress monitoring for Mater Dei College.</p>
        </div>
        <nav class="footer-links" aria-label="Footer">
          <a
            v-for="link in navLinks"
            :key="link.href"
            :href="link.href"
            @click="scrollToAnchor($event, link.href)"
          >{{ link.label }}</a>
        </nav>
        <p class="footer-note">
          Accounts are issued by your OJT coordinator. Trouble signing in? Contact the
          coordinator for your program.
        </p>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.page {
  /* Sampled from the campus photo: the building's navy trim and gold glazing. */
  --ink: #06172e;
  --navy: #0e2c53;
  --blue: #1c56b8;
  --gold: #d4a017;
  --paper: #f3f5f9;
  --line: #d9e0ea;
  --text: #16273d;
  --muted: #5a6c85;
  --display: 'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif;

  /*
   * Mock-UI only. The page's single accent is gold; these two exist because a
   * status pill inside the mock has to read as a status, not as a call to
   * action. They never appear outside a `.mock`.
   */
  --ok: #1f7a4d;

  color: var(--text);
  background: #fff;

  /*
   * DO NOT reinstate `overflow-x: hidden` here. An ancestor with any `overflow`
   * value other than `visible` becomes the scroll container for its
   * descendants, so `position: sticky`/`fixed` inside it resolves against THIS
   * box instead of the viewport — which is exactly why the header used to
   * scroll away with the page. If something overflows horizontally, fix the
   * element that overflows. `overflow-x: clip` is the only acceptable
   * container-level fallback, because it does not create a scroll container.
   */
}

.page *,
.page *::before,
.page *::after {
  box-sizing: border-box;
}

.display {
  font-family: var(--display);
  font-weight: 400;
}

.shell {
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 1.25rem;
}

.skip {
  position: absolute;
  left: -9999px;
  top: 0;
  z-index: 100;
  padding: 0.75rem 1.25rem;
  background: var(--gold);
  color: var(--ink);
  font-weight: 600;
  text-decoration: none;
}

.skip:focus {
  left: 0;
}

.page :focus-visible {
  outline: 2px solid var(--gold);
  outline-offset: 3px;
  border-radius: 2px;
}

/* ---------- Buttons ---------- */

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.85rem 1.6rem;
  border-radius: 999px;
  font-size: 0.95rem;
  font-weight: 600;
  text-decoration: none;
  border: 1px solid transparent;
  transition: background-color 0.18s ease, color 0.18s ease, border-color 0.18s ease;
}

.btn-sm {
  padding: 0.5rem 1.1rem;
  font-size: 0.875rem;
}

.btn-gold {
  background: var(--gold);
  color: var(--ink);
}

.btn-gold:hover {
  background: #e5af22;
}

.btn-gold:active {
  background: #bd8f13;
}

/* ---------- 1. Nav ---------- */

/*
 * `fixed`, not `sticky`. Sticky was the wrong tool once anything up the tree
 * could become a scroll container (see `.page`), and fixed is what "always
 * reachable, including from the footer" actually asks for.
 */
.nav {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 50;
  background: transparent;
  transition: background-color 0.25s ease, backdrop-filter 0.25s ease,
    border-color 0.25s ease;
  border-bottom: 1px solid transparent;
}

/* .96, not .92 — the links must never sit on a legible piece of photograph. */
.nav.is-solid {
  background: rgba(6, 23, 46, 0.96);
  backdrop-filter: blur(10px);
  border-bottom-color: rgba(255, 255, 255, 0.1);
}

/* ---------- The week rail ---------- */

.rail {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  width: 100%;
  height: 3px;
}

.rail-seg {
  background: rgba(255, 255, 255, 0.12);
  transition: background-color 0.25s ease;
}

.rail-seg.is-filled {
  background: var(--gold);
}

.nav-inner {
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
  padding: 0.9rem 1.25rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 0.65rem;
  text-decoration: none;
  color: #fff;
}

.mark {
  color: var(--gold);
  flex: none;
}

.brand-text {
  display: flex;
  flex-direction: column;
  line-height: 1.15;
}

.brand-name {
  font-family: var(--display);
  font-size: 1.15rem;
}

.brand-sub {
  font-size: 0.7rem;
  color: rgba(255, 255, 255, 0.72);
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 1.75rem;
}

.nav-links a {
  color: rgba(255, 255, 255, 0.86);
  text-decoration: none;
  font-size: 0.9rem;
  border-bottom: 2px solid transparent;
  transition: color 0.18s ease, border-color 0.18s ease;
}

.nav-links a:hover {
  color: #fff;
}

/* D. Where the reader currently is. A state change, not motion — so it is
 * deliberately left working under `prefers-reduced-motion`. */
.nav-links a.is-active {
  color: #fff;
  border-bottom-color: var(--gold);
  padding-bottom: 3px;
}

.nav-links .btn:hover {
  color: var(--ink);
}

.nav-toggle {
  display: none;
  padding: 0.5rem 0.9rem;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.45);
  background: transparent;
  color: #fff;
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}

.nav-panel {
  display: none;
}

/* ---------- 2. Hero ---------- */

/*
 * A column with its content pushed to the foot, and NO bottom padding — the
 * stat plinth is the last child and has to sit flush against the band below it.
 *
 * The old `margin-top: -4.4rem` is gone with the sticky header that needed it:
 * a FIXED header is out of flow, so the hero starts at y=0 and simply pads
 * itself clear of it. 84svh/720px rather than 92svh/820px — the taller box is
 * what forced the photograph to crop the building top and bottom.
 */
.hero {
  position: relative;
  min-height: min(84svh, 720px);
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 8rem 0 0;
  overflow: hidden;
}

.hero-photo,
.closing-photo {
  position: absolute;
  inset: 0;
  background-size: cover;
  background-repeat: no-repeat;
}

/*
 * 72% / 46% is measured, not guessed: it is the pair that keeps the chapel peak
 * AND both wings inside the frame from 1280px through 1920px. Lower the second
 * number and the wings clip at the bottom; raise the first and the left wing
 * slides under the copy panel.
 */
.hero-photo {
  background-position: 72% 46%;
}

/*
 * A diagonal panel, not a pair of flat scrims. It is opaque where the copy sits
 * and clear where the building is, so the type never lies over the architecture
 * — which is the whole composition. The second, vertical gradient is confined
 * to the bottom eighth and does one job: seating the plinth.
 */
.hero-scrim {
  position: absolute;
  inset: 0;
  background:
    linear-gradient(
      to bottom,
      rgba(6, 23, 46, 0) 78%,
      rgba(6, 23, 46, 0.55) 100%
    ),
    linear-gradient(
      105deg,
      var(--ink) 0%,
      rgba(6, 23, 46, 0.94) 34%,
      rgba(6, 23, 46, 0.55) 52%,
      rgba(6, 23, 46, 0.1) 72%
    );
}

.hero-inner {
  position: relative;
  color: #fff;
}

/*
 * The cap sits HERE and not on `.hero-inner`, which also carries `.shell`.
 * Overriding the shell's own max-width would pull the headline's left edge off
 * the 1200px grid the stat plinth below still sits on, and the two would stop
 * lining up at any viewport wider than the shell. Capped in rem rather than ch
 * because it answers to the picture behind it, not to the measure of the text.
 */
.hero-copy {
  max-width: 34rem;
}

.eyebrow {
  margin: 0 0 1.1rem;
  color: var(--gold);
  font-size: 0.85rem;
  font-weight: 600;
}

.hero-title {
  margin: 0;
  font-size: clamp(2.4rem, 5vw, 3.9rem);
  line-height: 1.05;
  letter-spacing: -0.01em;
}

.hero-lede {
  margin: 1.4rem 0 0;
  max-width: 46ch;
  font-size: 1.06rem;
  line-height: 1.6;
  color: rgba(255, 255, 255, 0.85);
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.85rem;
  margin-top: 2rem;
}

/*
 * `color: #fff` is load-bearing, not inherited chrome. The plinth is a SIBLING
 * of `.hero-inner`, which is the element carrying white text — so without this
 * the numerals fall back to the page's own `--text` (#16273d) and render dark
 * navy on a dark navy strip, i.e. invisible, while the labels stay readable
 * because they set their own colour. Caught on screen.
 */
/*
 * `padding: 0` on the plinth and the vertical padding moved onto the cells: it
 * is what lets each cell's left rule run the FULL height of the strip rather
 * than stopping short at the container's own padding box.
 */
.hero-plinth {
  position: relative;
  margin-top: clamp(2.75rem, 6vw, 4.5rem);
  padding: 0;
  color: #fff;
  background: rgba(6, 23, 46, 0.72);
  backdrop-filter: blur(6px);
  border-top: 1px solid rgba(255, 255, 255, 0.14);
}

/* `margin: 0 auto`, never a bare `margin: 0` — this element also carries
 * `.shell`, and zeroing the margin outright kills the auto-centering that keeps
 * these figures on the same left edge as the headline above them. */
.facts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0;
  margin: 0 auto;
}

.fact {
  padding: 1.5rem;
}

.fact + .fact {
  border-left: 1px solid rgba(255, 255, 255, 0.16);
}

/* The outer cells lose their outer padding so the first label and the last
 * numeral align with the shell grid the headline above them sits on. */
.fact:first-child {
  padding-left: 0;
}

.fact:last-child {
  padding-right: 0;
}

.fact dt {
  font-size: 0.82rem;
  color: rgba(255, 255, 255, 0.68);
}

/* `tabular-nums` so the three figures share a column rhythm rather than each
 * setting to its own glyph width. */
.fact dd {
  margin: 0.3rem 0 0;
  font-size: 2rem;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

/* ---------- Bands ---------- */

/* 5.5rem clears the fixed header plus the week rail, so a jumped-to section
 * never lands underneath it. */
.band {
  padding: clamp(4rem, 9vw, 7rem) 0;
  scroll-margin-top: 5.5rem;
}

/*
 * The lower two bands carry less content than the ones above them, so the full
 * band padding left each of them ending in a large empty gap and the page read
 * as running out of things to say. Keyed off the anchor ids they already have
 * rather than a new modifier class. The hero, roles and closing bands keep the
 * padding above.
 */
#record,
#more {
  padding: clamp(4rem, 7vw, 6rem) 0;
}

.band-paper {
  background: var(--paper);
}

.band-ink {
  background: var(--ink);
  color: #fff;
}

.band-navy {
  background: var(--navy);
  color: #fff;
}

.h2 {
  font-size: clamp(1.85rem, 3.4vw, 2.9rem);
  line-height: 1.14;
  letter-spacing: -0.01em;
  margin: 0;
}

.h2-wide {
  max-width: 24ch;
}

/* ---------- 3. The argument ---------- */

.split {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: clamp(2rem, 5vw, 4.5rem);
  align-items: start;
}

.split-body p {
  margin: 0 0 1.1rem;
  font-size: 1.02rem;
  line-height: 1.68;
  color: var(--muted);
}

.split-body p:last-child {
  margin-bottom: 0;
}

.cards {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1.75rem;
  margin-top: clamp(3rem, 6vw, 4.5rem);
}

/*
 * The three mocks hold different amounts of content, so without a floor and a
 * pushed-down footer the headings below them started on three different
 * baselines and the row read as ragged.
 */
.card {
  display: flex;
  flex-direction: column;
}

.card-title {
  margin: 1.5rem 0 0.6rem;
  font-size: 1.08rem;
  font-weight: 600;
  color: var(--ink);
}

.card-body {
  margin: 0;
  font-size: 0.95rem;
  line-height: 1.62;
  color: var(--muted);
}

/* ---------- Mock UI ---------- */

.mock {
  display: flex;
  flex-direction: column;
  min-height: 210px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 1rem;
  box-shadow: 0 10px 24px -18px rgba(6, 23, 46, 0.5);
}

.mock-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.mock-title {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--ink);
}

.pill {
  flex: none;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 600;
}

.pill-blue {
  background: rgba(28, 86, 184, 0.1);
  color: var(--blue);
}

.pill-green {
  background: rgba(31, 122, 77, 0.1);
  color: var(--ok);
}

.pill-gold {
  background: rgba(212, 160, 23, 0.16);
  color: #8a6708;
}

/* Neutral, for the two time-record states that are neither a warning nor an
 * achievement — built from existing tokens rather than a new colour. */
.pill-grey {
  background: var(--paper);
  color: var(--muted);
}

.mock-label {
  margin: 0.9rem 0 0.55rem;
  font-size: 0.75rem;
  color: var(--muted);
}

.bars {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.bar {
  display: block;
  height: 8px;
  border-radius: 4px;
  background: #e7ecf3;
  transition: background-color 0.18s ease;
}

.bar.is-locked {
  background: #dbe3ec;
}

.rows {
  margin-top: 0.9rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.5rem 0.65rem;
  border: 1px solid var(--line);
  border-radius: 9px;
  font-size: 0.76rem;
  color: var(--muted);
}

.row-day {
  font-weight: 600;
  color: var(--ink);
}

.row-span {
  font-variant-numeric: tabular-nums;
}

.row-total {
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}

.week {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 0.3rem;
  margin-top: 0.95rem;
}

.day {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 34px;
  border-radius: 7px;
  border: 1px solid var(--line);
  background: transparent;
  font: inherit;
  font-size: 0.7rem;
  color: var(--muted);
  cursor: pointer;
  transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
}

.day:hover:not(:disabled) {
  border-color: var(--navy);
}

/* A bundled week is closed and cannot be edited — which is the entire point the
 * card is making, so the cells must stop behaving like controls. */
.day:disabled {
  cursor: default;
}

.day.is-filled {
  background: var(--navy);
  border-color: var(--navy);
  color: #fff;
}

/* `margin-top: auto` is what drops each footer to the floor of its mock, so all
 * three mocks end at the same height and the headings below them agree. */
.mock-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: auto;
  padding-top: 0.75rem;
  border-top: 1px solid var(--line);
  font-size: 0.72rem;
  color: var(--muted);
}

.mock-total {
  flex: none;
  font-weight: 600;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

/* ---------- Mock controls ---------- */

.mock-btn {
  flex: none;
  font: inherit;
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--ink);
  background: transparent;
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 0.3rem 0.7rem;
  cursor: pointer;
  transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
}

.mock-btn:hover {
  background: var(--paper);
}

.mock-undo {
  flex: none;
  font: inherit;
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--muted);
  background: transparent;
  border: 0;
  padding: 0.3rem 0;
  cursor: pointer;
}

.mock-undo:hover {
  text-decoration: underline;
}

/* ---------- 4. Roles ---------- */

.tablist {
  display: flex;
  gap: 2rem;
  margin-top: clamp(2.5rem, 5vw, 3.75rem);
  border-bottom: 1px solid rgba(255, 255, 255, 0.14);
}

/*
 * `margin-bottom: -1px` is what sets the active tab's 2px gold rule ON the
 * tablist's own hairline rather than 1px above it.
 */
.tab {
  font: inherit;
  font-size: 1rem;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.6);
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  padding: 0 0 0.85rem;
  white-space: nowrap;
  cursor: pointer;
  transition: color 0.18s ease, border-color 0.18s ease;
}

.tab:hover {
  color: #fff;
}

.tab[aria-selected='true'] {
  color: #fff;
  border-bottom-color: var(--gold);
}

/* `min-height` so switching tabs never jumps the page under the reader. */
.role-panel {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: clamp(2rem, 5vw, 4rem);
  align-items: start;
  min-height: 15rem;
  padding-top: clamp(2rem, 4vw, 2.75rem);
}

.role-panel:focus-visible {
  outline-offset: 6px;
}

.role-name {
  margin: 0;
  font-size: 1.6rem;
}

.role-lead {
  margin: 0.5rem 0 0;
  color: var(--gold);
  font-size: 0.95rem;
  font-weight: 600;
}

/* Capped so the four bodies break at similar points and the panel does not
 * change shape as the reader moves between tabs. */
.role-body {
  margin: 1rem 0 0;
  max-width: 44ch;
  font-size: 0.95rem;
  line-height: 1.65;
  color: rgba(255, 255, 255, 0.72);
}

.ticks {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.ticks li {
  position: relative;
  padding-left: 1.1rem;
  font-size: 0.9rem;
  color: rgba(255, 255, 255, 0.9);
}

.ticks li::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0.5rem;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--gold);
}

/* ---------- The statement band ---------- */

/*
 * The roles band above is also `--ink`, so without this hairline the two run
 * together into one dark mass and the pause reads as more of the same section.
 * One pixel is the whole articulation — anything more would make this a card,
 * which is exactly what it must not be.
 */
.statement {
  background: var(--ink);
  color: #fff;
  padding: clamp(3.5rem, 7vw, 5.5rem) 0;
  text-align: center;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.statement-line {
  margin: 0;
  font-size: clamp(1.9rem, 4.4vw, 3.4rem);
  line-height: 1.15;
  letter-spacing: -0.01em;
}

.statement-accent {
  color: var(--gold);
}

.statement-sub {
  margin: 1.1rem 0 0;
  font-size: 0.95rem;
  color: rgba(255, 255, 255, 0.62);
}

/* ---------- 5. How it works ---------- */

/*
 * A timeline, not a stack of rows. The rule is drawn on `.steps::before` rather
 * than as a border on each step so it can STOP at the centre of the first and
 * last numerals — this is a sequence with a defined beginning and end, and a
 * rule running past either would say otherwise.
 *
 * `top`/`bottom` are `step padding (1.5rem) + half the numeral (1.375rem)`.
 * Those three numbers move together; changing the numeral size alone detaches
 * the rule from the circles.
 */
.steps {
  position: relative;
  margin: clamp(2.5rem, 5vw, 3.5rem) 0 0;
  padding: 0;
  list-style: none;
}

.steps::before {
  content: '';
  position: absolute;
  left: 1.375rem;
  top: 2.875rem;
  bottom: 2.875rem;
  width: 1px;
  background: var(--line);
}

.step {
  display: grid;
  grid-template-columns: 2.75rem minmax(0, 1fr);
  gap: 1.5rem;
  align-items: start;
  padding: 1.5rem 0;
}

/*
 * The `--paper` fill is what makes the rule appear to pass BEHIND the numeral:
 * the band is `--paper`, so the circle reads as a hole punched in the line
 * rather than a disc sitting on it.
 */
.step-num {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 50%;
  background: var(--paper);
  color: var(--gold);
  font-size: 1.4rem;
  line-height: 1;
}

.step-copy {
  padding-top: 0.4rem;
}

.step-title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--ink);
  line-height: 1.35;
}

.step-body {
  margin: 0.4rem 0 0;
  max-width: 62ch;
  font-size: 0.97rem;
  line-height: 1.65;
  color: var(--muted);
}

/* ---------- 6. The record ---------- */

/*
 * No `align-items` override here on purpose — `.split` already top-aligns, and
 * the `center` this rule used to carry is what floated the shorter column and
 * opened the dead gap beneath it. The lede's old 2rem bottom margin was clearing
 * a "Sign in to your portal" button that has since been removed; the paragraph
 * now ends the column.
 */
.record-lede {
  margin: 1.4rem 0 0;
  font-size: 1.02rem;
  line-height: 1.7;
  color: rgba(255, 255, 255, 0.78);
  max-width: 46ch;
}

.guarantees {
  margin: 0;
  border-top: 1px solid rgba(255, 255, 255, 0.18);
}

.guarantee {
  padding: 1.35rem 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.18);
}

.guarantee dt {
  font-size: 1rem;
  font-weight: 600;
  color: #fff;
}

.guarantee dd {
  margin: 0.35rem 0 0;
  font-size: 0.92rem;
  line-height: 1.6;
  color: rgba(255, 255, 255, 0.7);
}

/* ---------- 7. Everything else ---------- */

.extras {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  column-gap: clamp(1.75rem, 3.5vw, 2.75rem);
  row-gap: 2.25rem;
  align-content: start;
  margin: clamp(2.5rem, 5vw, 3.5rem) 0 0;
}

.extra {
  border-top: 2px solid var(--ink);
  padding-top: 1.1rem;
}

.extra dt {
  font-size: 1rem;
  font-weight: 600;
  color: var(--ink);
}

.extra dd {
  margin: 0.45rem 0 0;
  font-size: 0.93rem;
  line-height: 1.62;
  color: var(--muted);
}

/* ---------- 8. Closing ---------- */

/* A coda, so about a third less height than it carried as a second hero. */
.closing {
  position: relative;
  padding: clamp(3rem, 6.5vw, 5rem) 0;
  overflow: hidden;
  text-align: center;
  color: #fff;
}

/*
 * Desaturated so it reads as a PLATE behind the type. At full saturation the
 * blues and the grass competed with the hero and the page appeared to open
 * twice.
 */
.closing-photo {
  background-position: center 55%;
  filter: saturate(0.55);
}

/*
 * Deepening DOWNWARD rather than lightening: the band's foot now meets the
 * footer's flat `--ink` at nearly the same value, so the photograph fades into
 * it instead of stopping against a hard horizontal edge.
 */
.closing-scrim {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    to bottom,
    rgba(6, 23, 46, 0.86) 0%,
    rgba(6, 23, 46, 0.97) 100%
  );
}

.closing-inner {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.closing-inner .h2 {
  max-width: 20ch;
}

/* No bottom margin any more — it was clearing a "Sign in" button that has been
 * removed, and this line now ends the band. */
.closing-sub {
  margin: 1rem 0 0;
  font-size: 0.95rem;
  color: rgba(255, 255, 255, 0.75);
}

/* ---------- 9. Footer ---------- */

/* The hairline is what separates the footer from the closing band now that the
 * two are nearly the same value — see the closing scrim above. */
.footer {
  background: var(--ink);
  color: #fff;
  padding: clamp(3rem, 6vw, 4.5rem) 0;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}

/*
 * Three columns, so the coordinator note is a peer of the wordmark and the
 * links rather than a full-width strip pinned under both. Below 960px it stacks
 * in the same order.
 */
.footer-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) auto minmax(0, 1fr);
  gap: clamp(2rem, 5vw, 4rem);
  align-items: start;
}

.footer-name {
  font-size: 1.35rem;
}

.footer-brand p {
  margin: 0.6rem 0 0;
  max-width: 34ch;
  font-size: 0.92rem;
  line-height: 1.6;
  color: rgba(255, 255, 255, 0.66);
}

.footer-links {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  align-items: flex-start;
}

.footer-links a {
  color: rgba(255, 255, 255, 0.82);
  text-decoration: none;
  font-size: 0.9rem;
}

.footer-links a:hover {
  color: var(--gold);
}

/* No top rule any more — as a column it sits beside its peers rather than
 * under them, so a divider would cut across the row. */
.footer-note {
  margin: 0;
  max-width: 34ch;
  font-size: 0.85rem;
  line-height: 1.6;
  color: rgba(255, 255, 255, 0.6);
}

/* ---------- Responsive ---------- */

@media (max-width: 960px) {
  .nav-links {
    display: none;
  }

  .nav-toggle {
    display: inline-flex;
  }

  .nav-panel {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 0.5rem 1.25rem 1.25rem;
    background: rgba(6, 23, 46, 0.97);
    border-top: 1px solid rgba(255, 255, 255, 0.1);
  }

  .nav-panel a {
    color: rgba(255, 255, 255, 0.9);
    text-decoration: none;
    font-size: 0.95rem;
    padding: 0.6rem 0;
  }

  .nav-panel .btn {
    margin-top: 0.6rem;
    align-self: flex-start;
  }

  .split,
  .split-record {
    grid-template-columns: minmax(0, 1fr);
  }

  .cards,
  .extras {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  /* Stacks wordmark → links → note, the same order as the desktop row. */
  .footer-grid {
    grid-template-columns: minmax(0, 1fr);
    gap: 2rem;
  }
}

/*
 * Below 900px the diagonal stops working — there is no clear right-hand half
 * left to put a building in — so the panel becomes a flat vertical one and the
 * copy takes the full width, with the photo re-centred to keep the chapel in
 * frame at portrait proportions.
 */
@media (max-width: 900px) {
  .hero-photo {
    background-position: center 40%;
  }

  .hero-scrim {
    background: linear-gradient(
      to bottom,
      rgba(6, 23, 46, 0.92) 0%,
      rgba(6, 23, 46, 0.72) 100%
    );
  }

  .hero-copy {
    max-width: none;
  }
}

@media (max-width: 640px) {
  .shell,
  .nav-inner {
    padding-left: 1rem;
    padding-right: 1rem;
  }

  .hero {
    padding-top: 7rem;
  }

  /* Three short labels still fit three across at 375px, and keeping them on one
   * line is what preserves the plinth as a single strip. The gap stays 0 — the
   * separators are borders now, and a gap would detach them from the cells. */
  .fact {
    padding: 1.25rem 0.75rem;
  }

  .fact dt {
    font-size: 0.75rem;
  }

  .fact dd {
    font-size: 1.65rem;
  }

  /* The timeline keeps its structure; only the circle and the indent shrink —
   * and the rule's offsets have to follow the numeral, or it detaches. */
  .step {
    grid-template-columns: 2.25rem minmax(0, 1fr);
    gap: 1rem;
  }

  .step-num {
    width: 2.25rem;
    height: 2.25rem;
    font-size: 1.15rem;
  }

  .steps::before {
    left: 1.125rem;
    top: 2.625rem;
    bottom: 2.625rem;
  }

  .cards,
  .extras {
    grid-template-columns: minmax(0, 1fr);
  }

  /*
   * The tab row scrolls sideways in its OWN container rather than collapsing to
   * an accordion — four short labels stay scannable that way, and the page
   * itself never scrolls horizontally.
   */
  .tablist {
    gap: 1.5rem;
    overflow-x: auto;
    scrollbar-width: none;
  }

  .tablist::-webkit-scrollbar {
    display: none;
  }

  .role-panel {
    grid-template-columns: minmax(0, 1fr);
    min-height: 0;
  }

  .row {
    flex-wrap: wrap;
  }
}

@media (prefers-reduced-motion: reduce) {
  .page *,
  .page *::before,
  .page *::after {
    transition: none !important;
    animation: none !important;
    scroll-behavior: auto !important;
  }
}
</style>

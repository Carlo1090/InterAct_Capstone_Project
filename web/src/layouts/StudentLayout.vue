<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import SidebarCollapseToggle from '@/components/layout/SidebarCollapseToggle.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import NotificationBell from '@/components/notifications/NotificationBell.vue'
import ProfileMenuPopover from '@/components/profile/ProfileMenuPopover.vue'

const INFO_SHEET_ITEM = { label: 'Student Info Sheet', to: '/student/info-sheet', badge: '', icon: 'id-card' }

/**
 * THE SIDEBAR IS GROUPED, NOT FLAT (2026-09-08) — the same treatment, headings
 * and rail behaviour as CoordinatorLayout; see its NAV_SECTIONS block for the
 * reasoning and the collapsed-rail rule.
 *
 * The split follows what a student is actually doing at the time: Journals is
 * the writing they do most days, Time & Attendance is where their HOURS come
 * from (the typed summary and, where it runs, the clocked record), and My Forms
 * is the two documents that bookend the placement. Dashboard stays outside any
 * section.
 *
 * Order WITHIN each group is unchanged from the flat list it replaced — only
 * the grouping is new.
 */
const NAV_SECTIONS = [
  {
    heading: null,
    items: [{ label: 'Dashboard', to: '/student/dashboard', badge: '', icon: 'dashboard' }],
  },
  {
    heading: 'Journals',
    items: [
      { label: 'My Journal Calendar', to: '/student/calendar', badge: '', icon: 'calendar' },
      { label: 'My Journals', to: '/student/journals', badge: '', icon: 'journals' },
      { label: 'Write Daily Journal', to: '/student/write-journal', badge: '', icon: 'pencil' },
      { label: 'Weekly Journals', to: '/student/weekly-journals', badge: '', icon: 'stack' },
    ],
  },
  {
    heading: 'Time & Attendance',
    items: [
      { label: 'Weekly and Time Log Summary', to: '/student/weekly-time-log', badge: '', icon: 'clock' },
      // Shown only where the batch coordinator enabled the QR/geofence DTR —
      // see the navSections filter below. 'clock' is already taken by the
      // weekly summary above, so this uses its own pin glyph.
      { label: 'Daily Time Record', to: '/student/dtr', badge: '', icon: 'map-pin' },
    ],
  },
  {
    heading: 'My Forms',
    items: [
      INFO_SHEET_ITEM,
      // The last form of the placement. Sits after the info sheet because that
      // is the order a student meets them: intake first, exit last.
      { label: 'Exit Interview', to: '/student/exit-interview', badge: '', icon: 'exit' },
    ],
  },
]

const auth = useAuthStore()
const route = useRoute()

// Desktop-only rail collapse (the circular chevron). On phones the sidebar is
// instead an off-canvas drawer driven by `mobileOpen`.
const collapsed = ref(false)
const mobileOpen = ref(false)

// Until their info sheet is approved, a student may only reach the info-sheet
// page — so the rest of the nav is hidden while gated. The header's account
// popover (Edit Profile/Change Password/Activity Log/Log Out) stays reachable
// regardless, since it's not part of this filtered nav list — the backend
// already allows those endpoints while gated too.
const isGated = computed(() => auth.user?.role === 'student' && auth.user?.student_gated === true)
// Dropped from their batch: keep only the Info Sheet link reachable (their
// journal pages have no active enrollment to load). The router guard bounces
// everything else to /student/paused.
const isPaused = computed(
  () => auth.user?.role === 'student' && auth.user?.student_gated !== true && auth.user?.student_paused === true,
)
// Not every programme uses the Daily Time Record: it anchors to a fixed
// workplace, which a student on rotating or field placement does not have.
// The coordinator decides, and the backend 403s these students on every DTR
// endpoint — hiding the link keeps the nav honest rather than offering a page
// that would only refuse them.
const hasDtr = computed(() => auth.user?.student_dtr_enabled === true)
const navSections = computed(() => {
  // Gated or paused, the whole nav collapses to the one page they may reach.
  // Deliberately returned as a SINGLE UNHEADED section rather than as the
  // filtered "My Forms" group: a lone item under a heading announces a
  // category that has nothing else in it, which is exactly the impression a
  // gated student should not be given about a nav they cannot use yet.
  if (isGated.value || isPaused.value) {
    return [{ heading: null, items: [INFO_SHEET_ITEM] }]
  }

  return NAV_SECTIONS.map((section) => ({
    ...section,
    items: section.items.filter((item) => item.to !== '/student/dtr' || hasDtr.value),
  })).filter((section) => section.items.length > 0)
})

const pageTitle = computed(() => (typeof route.meta.title === 'string' ? route.meta.title : 'Dashboard'))
const userName = computed(() => auth.user?.name ?? 'Student')
const department = computed(() => auth.user?.program?.department?.name ?? 'CAST')
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-800">
    <div class="fixed inset-x-0 top-0 z-30 h-1.5 bg-slate-900" />

    <!-- Mobile drawer backdrop -->
    <div
      v-if="mobileOpen"
      class="fixed inset-0 z-30 bg-black/40 md:hidden"
      @click="mobileOpen = false"
    />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col overflow-visible bg-linear-to-b from-blue-600 to-indigo-700 text-white transition-all duration-300 ease-in-out md:z-20 md:translate-x-0"
      :class="[
        collapsed ? 'md:w-20' : 'md:w-64',
        mobileOpen ? 'translate-x-0' : '-translate-x-full',
      ]"
    >
      <div class="flex items-center gap-3 px-5 py-5" :class="collapsed && 'justify-center px-0'">
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white shadow">
          <img src="/images/mdc-logo.png" alt="Mater Dei College seal" class="h-9 w-9 rounded-full object-contain" />
        </div>
        <p v-if="!collapsed" class="truncate text-base font-bold">InternTrack</p>
      </div>

      <div class="mx-3 mb-2 border-t border-white/20" />

      <nav class="flex-1 overflow-y-auto px-3 py-2">
        <div
          v-for="(section, sectionIndex) in navSections"
          :key="section.heading ?? 'primary'"
          role="group"
          :aria-label="section.heading ?? undefined"
          class="space-y-1"
        >
          <!--
            Collapsed to the 76px rail there is nowhere for a text heading to
            go, so the grouping degrades to a rule — the same `!collapsed`
            switch the item labels themselves use, so a heading can never
            outlive the labels it sits above. `aria-label` on the group
            survives either way, so the structure is still announced when it is
            invisible.

            The first section is skipped entirely: it holds the single landing
            item, and a heading (or a rule) above it would only push it down
            for nothing.
          -->
          <template v-if="sectionIndex > 0">
            <p
              v-if="!collapsed"
              class="px-3 pt-3 pb-1 text-[10px] font-bold tracking-wider text-blue-200/70 uppercase"
            >
              {{ section.heading }}
            </p>
            <div v-else class="mx-1 my-3 border-t border-white/15" />
          </template>

          <component
            :is="collapsed ? TooltipWrap : 'div'"
            v-for="item in section.items"
            :key="item.to"
            v-bind="collapsed ? { label: item.label, placement: 'right' } : {}"
            class="w-full"
          >
        <RouterLink
          :to="item.to"
          class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium text-blue-100 transition hover:bg-white/10 hover:text-white"
          :class="collapsed && 'md:justify-center md:px-0'"
          active-class="bg-white/15 text-white"
          @click="mobileOpen = false"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 shrink-0">
            <rect v-if="item.icon === 'dashboard'" x="3.5" y="3.5" width="7" height="7" rx="1.5" fill="currentColor" />
            <rect v-if="item.icon === 'dashboard'" x="13.5" y="3.5" width="7" height="7" rx="1.5" fill="currentColor" />
            <rect v-if="item.icon === 'dashboard'" x="3.5" y="13.5" width="7" height="7" rx="1.5" fill="currentColor" />
            <rect v-if="item.icon === 'dashboard'" x="13.5" y="13.5" width="7" height="7" rx="1.5" fill="currentColor" />

            <rect v-if="item.icon === 'calendar'" x="3.5" y="4.5" width="17" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
            <path v-if="item.icon === 'calendar'" d="M3.5 9.5h17M8 3v3M16 3v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />

            <rect v-if="item.icon === 'journals'" x="4.5" y="3.5" width="15" height="17" rx="1.5" stroke="currentColor" stroke-width="1.6" />
            <path v-if="item.icon === 'journals'" d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />

            <path
              v-if="item.icon === 'pencil'"
              d="m4 20 .8-3.6L15.5 5.7a1.5 1.5 0 0 1 2.1 0l.7.7a1.5 1.5 0 0 1 0 2.1L7.6 19.2 4 20Z"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linejoin="round"
            />

            <path
              v-if="item.icon === 'stack'"
              d="m12 4 8 4.5-8 4.5-8-4.5L12 4Z"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linejoin="round"
            />
            <path v-if="item.icon === 'stack'" d="m4 13.5 8 4.5 8-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />

            <circle v-if="item.icon === 'clock'" cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
            <path v-if="item.icon === 'clock'" d="M12 7.5V12l3 1.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />

            <path
              v-if="item.icon === 'map-pin'"
              d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linejoin="round"
            />
            <circle v-if="item.icon === 'map-pin'" cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.6" />

            <rect v-if="item.icon === 'id-card'" x="3.5" y="5.5" width="17" height="13" rx="2" stroke="currentColor" stroke-width="1.6" />
            <circle v-if="item.icon === 'id-card'" cx="9" cy="11.5" r="2" stroke="currentColor" stroke-width="1.6" />
            <path v-if="item.icon === 'exit'" d="M14 4.5H6.5A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5H14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            <path v-if="item.icon === 'exit'" d="M15.5 8.5 19 12l-3.5 3.5M10.5 12H19" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />

            <path v-if="item.icon === 'id-card'" d="M6.5 15.5c.6-1.4 1.8-2 2.5-2s1.9.6 2.5 2M14 10h4M14 13h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />

            <circle v-if="item.icon === 'profile'" cx="12" cy="8.5" r="3.5" stroke="currentColor" stroke-width="1.6" />
            <path v-if="item.icon === 'profile'" d="M4.5 19.5c1-3.6 3.8-5.5 7.5-5.5s6.5 1.9 7.5 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
          </svg>
          <span v-if="!collapsed" class="min-w-0 flex-1 truncate">{{ item.label }}</span>
          <span
            v-if="item.badge && !collapsed"
            class="rounded-full bg-red-500 px-2 py-0.5 text-xs font-bold text-white"
          >{{ item.badge }}</span>
        </RouterLink>
          </component>
        </div>
      </nav>

      <SidebarCollapseToggle :collapsed="collapsed" @toggle="collapsed = !collapsed" />
    </aside>

    <div class="min-h-screen transition-[margin] duration-300 ease-in-out" :class="collapsed ? 'md:ml-20' : 'md:ml-64'">
      <header class="sticky top-0 z-10 flex h-16 items-center justify-between gap-2 border-b border-slate-200 bg-white px-4 md:px-8">
        <div class="flex min-w-0 items-center gap-3">
          <button
            type="button"
            class="-ml-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 md:hidden"
            aria-label="Open menu"
            @click="mobileOpen = true"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-6 w-6">
              <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
          </button>
          <!--
            THE page title, and the only one. Pages must not print their own
            copy in the body: this renders `route.meta.title`, which is the same
            string as the active sidebar label, so a body heading repeating it
            put the same word on screen three times. The 11 pages that used to
            do that had their duplicate <h2> removed (subtitles and action
            buttons kept) — don't reintroduce one.
          -->
          <h1 class="truncate text-base font-bold text-slate-950 md:text-lg">{{ pageTitle }}</h1>
        </div>

        <div class="flex shrink-0 items-center gap-3">
          <!-- Hidden on phones: a long name wraps and collides with the page title. -->
          <div class="hidden text-right leading-tight sm:block">
            <p class="text-sm font-bold uppercase tracking-wide text-slate-700">{{ userName }}</p>
            <p class="text-xs text-slate-400">Student &middot; {{ department }}</p>
          </div>
          <NotificationBell />
          <ProfileMenuPopover />
        </div>
      </header>

      <main class="p-4 md:p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>

import { createRouter, createWebHistory, type RouteLocationNormalized } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
// The ONLY statically-imported page in this file, and deliberately so — see the
// '/' route below.
import LandingPage from '@/pages/LandingPage.vue'

export const roleRedirect = (role: string | null): string => {
  const redirects: Record<string, string> = {
    admin: '/admin/dashboard',
    coordinator: '/coordinator/dashboard',
    supervisor: '/supervisor/dashboard',
    student: '/student/dashboard',
  }

  return role ? redirects[role] ?? '/login' : '/login'
}

const pageTitle = (route: RouteLocationNormalized): string => {
  return typeof route.meta.title === 'string' ? route.meta.title : 'Dashboard'
}

const router = createRouter({
  history: createWebHistory(),
  routes: [
    /*
     * The public landing page — the app's front door. '/' used to redirect
     * straight to '/login'.
     *
     * Eagerly imported, unlike every other route in this file. It is the first
     * paint for an unauthenticated visitor arriving at the bare domain, and a
     * dynamic import costs a second round trip (entry chunk, THEN the page)
     * before anything renders at all.
     *
     * The cost was measured, not assumed: lazy gives a 41.20 kB gzip entry plus
     * a 6.26 kB page chunk and a 0.44 kB CSS chunk; eager gives a 47.12 kB
     * entry and nothing else. So this trades ~5.9 kB gzip on every OTHER page
     * load in the app against one saved round trip for every public visitor.
     * At that size the round trip is worth more. Re-measure before reverting.
     *
     * No `requiresAuth`, for the same reason the password-reset routes carry
     * none: it must stay reachable with no session whatsoever.
     *
     * A signed-in user visiting '/' sees this page rather than their dashboard,
     * and that is deliberate. Bouncing them would need auth.user populated, but
     * beforeEach only calls fetchUser() for `requiresAuth` routes — so the
     * bounce would fire on in-app navigation and silently not fire on a cold
     * load with a perfectly valid session. Adding a blocking fetchUser() here
     * to close that gap would put an API round trip in front of every public
     * visitor's first paint, which is exactly backwards for a marketing page.
     * Note this is not a regression: '/' already landed a signed-in user on the
     * login form, since LoginPage has never redirected an authenticated user.
     */
    {
      path: '/',
      name: 'landing',
      component: LandingPage,
      meta: { title: 'Internship Journal and Progress Monitoring System' },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/LoginPage.vue'),
      meta: { title: 'Login' },
    },
    /*
     * Password reset. Both are public — carrying `requiresAuth` would bounce a
     * locked-out user to /login, which is the one place they cannot get past.
     *
     * The token path is '/password-reset/:token', NOT '/reset-password': it has
     * to match the URL AppServiceProvider::boot() builds into the email
     * (`{FRONTEND_URL}/password-reset/{token}?email=...`), and it deliberately
     * differs from the API's own POST path so the deployed rewrite cannot
     * swallow the page load. The credentials POSTs go to '/auth/forgot-password'
     * and '/auth/reset-password' for the same reason '/auth/login' exists.
     */
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/pages/ForgotPasswordPage.vue'),
      meta: { title: 'Forgot Password' },
    },
    {
      path: '/password-reset/:token',
      name: 'password-reset',
      component: () => import('@/pages/ResetPasswordPage.vue'),
      meta: { title: 'Reset Password' },
    },
    {
      path: '/admin',
      component: () => import('@/layouts/AdminLayout.vue'),
      redirect: '/admin/dashboard',
      meta: { requiresAuth: true, role: 'admin' },
      children: [
        {
          path: 'dashboard',
          component: () => import('@/pages/admin/AdminDashboardPage.vue'),
          meta: { title: 'Admin Dashboard' },
        },
        {
          path: 'users',
          component: () => import('@/pages/admin/AdminUsersPage.vue'),
          meta: { title: 'Users' },
        },
        {
          path: 'departments',
          component: () => import('@/pages/admin/AdminDepartmentsPage.vue'),
          meta: { title: 'Departments' },
        },
        {
          path: 'programs',
          component: () => import('@/pages/admin/AdminProgramsPage.vue'),
          meta: { title: 'Programs' },
        },
        {
          path: 'batches',
          component: () => import('@/pages/admin/AdminBatchesPage.vue'),
          meta: { title: 'Batches' },
        },
        {
          path: 'info-sheets',
          component: () => import('@/pages/admin/AdminInfoSheetsPage.vue'),
          meta: { title: 'Student Info Sheet' },
        },
        {
          path: 'annual-sipp',
          component: () => import('@/pages/admin/AdminAnnualSippPage.vue'),
          meta: { title: 'Annual SIPP Report' },
        },
        {
          path: 'audit-logs',
          component: () => import('@/pages/admin/AdminAuditLogsPage.vue'),
          meta: { title: 'Audit Logs' },
        },
        {
          path: 'settings',
          component: () => import('@/pages/admin/AdminSystemSettingsPage.vue'),
          meta: { title: 'System Settings' },
        },
      ],
    },
    {
      path: '/coordinator',
      component: () => import('@/layouts/CoordinatorLayout.vue'),
      redirect: '/coordinator/dashboard',
      meta: { requiresAuth: true, role: 'coordinator' },
      children: [
        {
          path: 'dashboard',
          component: () => import('@/pages/coordinator/CoordinatorDashboardPage.vue'),
          meta: { title: 'Coordinator Dashboard' },
        },
        {
          path: 'users',
          component: () => import('@/pages/coordinator/CoordinatorInternsPage.vue'),
          meta: { title: 'Users' },
        },
        // Backward-compatible redirect from the old "Interns" path.
        {
          path: 'interns',
          redirect: '/coordinator/users',
        },
        {
          path: 'journal-activities',
          component: () => import('@/pages/coordinator/CoordinatorJournalActivitiesPage.vue'),
          meta: { title: 'Daily Journal Activities' },
        },
        {
          path: 'weekly-journals',
          component: () => import('@/pages/coordinator/CoordinatorWeeklyJournalsPage.vue'),
          meta: { title: 'Weekly Journals' },
        },
        {
          // The coordinator's OWN review queue, for coordinator-centered
          // batches only. Distinct from weekly-journals above, which stays
          // read-only monitoring across every batch in scope.
          path: 'journal-review',
          component: () => import('@/pages/coordinator/CoordinatorJournalReviewPage.vue'),
          meta: { title: 'Journal Review' },
        },
        {
          // The SAME notebook component the supervisor uses. Both roles read
          // the same document and give the same two verdicts; only the API
          // prefix differs, and the page derives that from this route's path.
          path: 'journal-review/interns/:studentId',
          component: () => import('@/pages/supervisor/SupervisorInternJournalsPage.vue'),
          meta: { title: 'Intern Journals' },
        },
        {
          path: 'weekly-time-logs',
          component: () => import('@/pages/coordinator/CoordinatorWeeklyTimeLogsPage.vue'),
          meta: { title: 'Weekly and Time Log Summaries' },
        },
        {
          path: 'exit-interviews',
          component: () => import('@/pages/coordinator/CoordinatorExitInterviewsPage.vue'),
          meta: { title: 'Student Exit Interviews' },
        },
        {
          path: 'dtr',
          component: () => import('@/pages/coordinator/CoordinatorDtrPage.vue'),
          meta: { title: 'Daily Time Record' },
        },
        {
          path: 'journal-templates',
          component: () => import('@/pages/coordinator/CoordinatorJournalTemplatesPage.vue'),
          meta: { title: 'Journal Templates' },
        },
        {
          path: 'batches',
          component: () => import('@/pages/coordinator/CoordinatorBatchesPage.vue'),
          meta: { title: 'Batches' },
        },
        {
          path: 'companies',
          component: () => import('@/pages/coordinator/CoordinatorCompaniesPage.vue'),
          meta: { title: 'Partner Companies' },
        },
        {
          path: 'info-sheets',
          component: () => import('@/pages/coordinator/CoordinatorInfoSheetsPage.vue'),
          meta: { title: 'Student Info Sheets' },
        },
        {
          path: 'group-info-sheets',
          component: () => import('@/pages/coordinator/CoordinatorGroupInfoSheetsPage.vue'),
          meta: { title: 'Group Info Sheets' },
        },
        {
          path: 'annual-sipp',
          component: () => import('@/pages/coordinator/CoordinatorAnnualSippPage.vue'),
          meta: { title: 'Annual SIPP Report' },
        },
        {
          path: 'hte',
          component: () => import('@/pages/coordinator/CoordinatorHtePage.vue'),
          meta: { title: 'HTE & Student Interns List' },
        },
      ],
    },
    {
      path: '/supervisor',
      component: () => import('@/layouts/SupervisorLayout.vue'),
      redirect: '/supervisor/dashboard',
      meta: { requiresAuth: true, role: 'supervisor' },
      children: [
        {
          path: 'dashboard',
          component: () => import('@/pages/supervisor/SupervisorDashboardPage.vue'),
          meta: { title: 'Supervisor Dashboard' },
        },
        {
          path: 'journals',
          component: () => import('@/pages/supervisor/SupervisorJournalsPage.vue'),
          meta: { title: 'Journals' },
        },
        {
          path: 'interns',
          component: () => import('@/pages/supervisor/SupervisorInternsPage.vue'),
          meta: { title: 'Interns' },
        },
        {
          // One intern's whole journal notebook. A page rather than a modal:
          // it is a reading surface the supervisor stays in, and it is worth
          // being linkable and back-button-able.
          path: 'interns/:studentId/journals',
          component: () => import('@/pages/supervisor/SupervisorInternJournalsPage.vue'),
          meta: { title: 'Intern Journals' },
        },
        {
          path: 'dtr',
          component: () => import('@/pages/supervisor/SupervisorDtrPage.vue'),
          meta: { title: 'Daily Time Record' },
        },
      ],
    },
    {
      path: '/student',
      component: () => import('@/layouts/StudentLayout.vue'),
      redirect: '/student/dashboard',
      meta: { requiresAuth: true, role: 'student' },
      children: [
        {
          path: 'dashboard',
          component: () => import('@/pages/student/StudentDashboardPage.vue'),
          meta: { title: 'Student Dashboard' },
        },
        {
          path: 'calendar',
          component: () => import('@/pages/student/StudentCalendarPage.vue'),
          meta: { title: 'My Journal Calendar' },
        },
        {
          path: 'journals',
          component: () => import('@/pages/student/StudentJournalsPage.vue'),
          meta: { title: 'My Journals' },
        },
        {
          path: 'write-journal',
          component: () => import('@/pages/student/StudentWriteJournalPage.vue'),
          meta: { title: 'Write Daily Journal' },
        },
        {
          path: 'weekly-journals',
          component: () => import('@/pages/student/StudentWeeklyJournalsPage.vue'),
          meta: { title: 'Weekly Journals' },
        },
        {
          path: 'weekly-time-log',
          component: () => import('@/pages/student/StudentWeeklyTimeLogPage.vue'),
          meta: { title: 'Weekly and Time Log Summary' },
        },
        {
          path: 'exit-interview',
          component: () => import('@/pages/student/StudentExitInterviewPage.vue'),
          meta: { title: 'Exit Interview' },
        },
        {
          path: 'dtr',
          component: () => import('@/pages/student/StudentDtrPage.vue'),
          meta: { title: 'Daily Time Record' },
        },
        {
          // The QR landing page. Registered BEFORE nothing in particular, but
          // kept adjacent to /student/dtr so the pair stays obvious: a printed
          // code opens this path with ?s=<token> in the phone's own browser.
          path: 'dtr/scan',
          component: () => import('@/pages/student/StudentDtrScanPage.vue'),
          meta: { title: 'Clock In' },
        },
        {
          path: 'info-sheet',
          component: () => import('@/pages/student/StudentInfoSheetPage.vue'),
          meta: { title: 'Student Info Sheet' },
        },
        {
          path: 'paused',
          component: () => import('@/pages/student/StudentPausedPage.vue'),
          meta: { title: 'Enrollment Inactive' },
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.user) {
    try {
      await auth.fetchUser()
    } catch {
      // Remember where they were going. This is load-bearing for the DTR QR
      // flow: a student scans a printed code with their phone camera, which
      // opens the scan URL in whichever browser is default — very often one
      // with no session. Without the round trip back, they log in and land on
      // the dashboard with the site token gone, and the scan has to be redone.
      return { path: '/login', query: { redirect: to.fullPath } }
    }
  }

  if (to.meta.role && auth.user?.role !== to.meta.role) {
    // Carry the intended path, exactly as the unauthenticated branch above
    // does. A bare '/login' silently ate the DTR site token whenever the phone
    // held a NON-student session: a supervisor or coordinator opening a
    // clock-in QR landed on a plain login form, and signing in as the student
    // then went to the dashboard with the scan lost and nothing explaining
    // why. Same open-redirect protection applies — LoginPage's
    // redirectTarget() accepts only same-origin relative paths, and /login
    // carries no requiresAuth, so there is no guard loop.
    return { path: '/login', query: { redirect: to.fullPath, wrong_role: '1' } }
  }

  // A forced password change (e.g. an admin-issued temporary password) is no
  // longer route-enforced — there's no dedicated Profile page to redirect to
  // anymore. ProfileMenuPopover.vue (mounted in every layout header) watches
  // auth.user.must_change_password itself and locks the user into its Change
  // Password view with a blocking backdrop until they save a new one.

  // Info-sheet enrollment gate: a not-yet-approved student may only reach the
  // info-sheet page. Backend enforces this too. (The account popover — Edit
  // Profile/Change Password/Activity Log — stays reachable regardless, since
  // it's not a route.)
  //
  // A scan bounced here loses its site token, and the student cannot un-gate
  // themselves — so ?from=scan is carried purely so the destination can say
  // WHY their clock-in did not record, instead of looking like a random
  // redirect away from the QR code they just scanned.
  if (
    auth.user?.role === 'student' &&
    auth.user?.student_gated &&
    to.path !== '/student/info-sheet'
  ) {
    return to.path === '/student/dtr/scan'
      ? { path: '/student/info-sheet', query: { from: 'scan' } }
      : '/student/info-sheet'
  }

  // Dropped-from-batch state: a student past intake but with no active/completed
  // enrollment sees the calm "enrollment inactive" page instead of the journal
  // pages, which would otherwise error (no current enrollment to resolve).
  if (
    auth.user?.role === 'student' &&
    !auth.user?.student_gated &&
    auth.user?.student_paused &&
    to.path !== '/student/paused' &&
    to.path !== '/student/info-sheet'
  ) {
    return to.path === '/student/dtr/scan'
      ? { path: '/student/paused', query: { from: 'scan' } }
      : '/student/paused'
  }

  document.title = `${pageTitle(to)} | InternTrack`

  return true
})

export default router

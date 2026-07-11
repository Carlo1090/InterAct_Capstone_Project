import { createRouter, createWebHistory, type RouteLocationNormalized } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

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
    { path: '/', redirect: '/login' },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/LoginPage.vue'),
      meta: { title: 'Login' },
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
          path: 'info-sheet',
          component: () => import('@/pages/student/StudentInfoSheetPage.vue'),
          meta: { title: 'Student Info Sheet' },
        },
        {
          path: 'change-password',
          component: () => import('@/pages/student/StudentChangePasswordPage.vue'),
          meta: { title: 'Change Password' },
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
      return '/login'
    }
  }

  if (to.meta.role && auth.user?.role !== to.meta.role) {
    return '/login'
  }

  if (auth.user?.must_change_password && to.path !== '/student/change-password') {
    return '/student/change-password'
  }

  document.title = `${pageTitle(to)} | InternTrack`

  return true
})

export default router

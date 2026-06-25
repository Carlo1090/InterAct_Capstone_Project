import StudentDashboard from '../views/student/StudentDashboard.vue'
import SupervisorDashboard from '../views/supervisor/SupervisorDashboard.vue'
import CoordinatorDashboard from '../views/coordinator/CoordinatorDashboard.vue'
import AdminDashboard from '../views/admin/AdminDashboard.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/student', component: StudentDashboard },
    { path: '/supervisor', component: SupervisorDashboard },
    { path: '/coordinator', component: CoordinatorDashboard },
    { path: '/admin', component: AdminDashboard },
  ],
})

export default router

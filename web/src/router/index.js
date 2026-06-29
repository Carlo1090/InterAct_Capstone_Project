import { createRouter, createWebHistory } from 'vue-router'

import StudentDashboard from '../views/student/StudentDashboard.vue'
import SupervisorDashboard from '../views/supervisor/SupervisorDashboard.vue'
import CoordinatorDashboard from '../views/coordinator/CoordinatorDashboard.vue'
import AdminDashboard from '../views/admin/AdminDashboard.vue'
import UserManagement from '../views/admin/UserManagement.vue'
import Departments from '../views/admin/Departments.vue'
import BatchManagement from '../views/admin/BatchManagement.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/student', component: StudentDashboard },
    { path: '/supervisor', component: SupervisorDashboard },
    { path: '/coordinator', component: CoordinatorDashboard },
    { path: '/admin', component: AdminDashboard },
    { path: '/admin/users', component: UserManagement },
    { path: '/admin/departments', component: Departments },
    { path: '/admin/batches', component: BatchManagement },
  ],
})

export default router

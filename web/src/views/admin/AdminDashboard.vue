<script setup>
import DashboardShell from '../../components/layout/DashboardShell.vue'

const navItems = [
  { label: 'Dashboard', to: '/admin' },
  { label: 'User Management', to: '/admin/users' },
  { label: 'Departments', to: '/admin/departments' },
  { label: 'Batch Management', to: '/admin/batches' },
  { label: 'Companies', to: '/admin/companies' },
  { label: 'Student Info Sheet', to: '/admin/student-info-sheets' },
  { label: 'Annual SIPP Report', to: '/admin/sipp-report' },
  { label: 'System Settings', to: '/admin/settings' },
  { label: 'Audit Logs', to: '/admin/audit-logs' },
]
</script>

<template>
  <DashboardShell role-label="Admin" :nav-items="navItems">
    <p class="text-slate-500">
      User Management, Departments, and Batch Management are live and call
      the real API. The remaining pages (Companies, Student Info Sheet,
      Annual SIPP Report, System Settings, Audit Logs) are built in later
      phases per the roadmap.
    </p>
  </DashboardShell>
</template>

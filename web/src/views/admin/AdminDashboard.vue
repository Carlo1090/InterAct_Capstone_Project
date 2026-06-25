<script setup>
import DashboardShell from '../../components/layout/DashboardShell.vue'

const navItems = [
  'Dashboard',
  'User Management',
  'Departments',
  'Batch Management',
  'Companies',
  'Student Info Sheet',
  'Annual SIPP Report',
  'System Settings',
  'Audit Logs',
]
</script>

<template>
  <DashboardShell role-label="Admin" :nav-items="navItems">
    <p class="text-slate-500">
      Admin dashboard content goes here. Account, department, and batch
      management endpoints get wired in starting Phase 2.
    </p>
  </DashboardShell>
</template>

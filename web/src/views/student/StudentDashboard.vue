<script setup>
import DashboardShell from '../../components/layout/DashboardShell.vue'

const navItems = [
  { label: 'Dashboard', to: '/student' },
  { label: 'Write Daily Journal', to: '/student' },
  { label: 'My Journals', to: '/student' },
  { label: 'My Journal Calendar', to: '/student' },
  { label: 'Weekly Journals', to: '/student' },
  { label: 'Student Info Sheet', to: '/student' },
  { label: 'Generate Report', to: '/student' },
]
</script>

<template>
  <DashboardShell role-label="Student" :nav-items="navItems">
    <p class="text-slate-500">
      Student dashboard content goes here. Real journal data, calendar status,
      and notifications get wired in starting Phase 3.
    </p>
  </DashboardShell>
</template>

<script setup>
import DashboardShell from '../../components/layout/DashboardShell.vue'

const navItems = [
  { label: 'Dashboard', to: '/supervisor' },
  { label: 'My Assigned Students', to: '/supervisor' },
  { label: 'Review Weekly Journals', to: '/supervisor' },
]
</script>

<template>
  <DashboardShell role-label="Supervisor" :nav-items="navItems">
    <p class="text-slate-500">
      Supervisor dashboard content goes here. Weekly log review and approval
      workflow gets wired in starting Phase 4.
    </p>
  </DashboardShell>
</template>

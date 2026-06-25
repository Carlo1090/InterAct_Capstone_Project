<script setup>
import DashboardShell from '../../components/layout/DashboardShell.vue'

const navItems = [
  'Dashboard',
  'My Assigned Students',
  'Review Weekly Journals',
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

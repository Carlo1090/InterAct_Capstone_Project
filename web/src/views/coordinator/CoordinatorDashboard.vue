<script setup>
import DashboardShell from '../../components/layout/DashboardShell.vue'

const navItems = [
  'Dashboard',
  'Daily Journal Activities',
  'Student Info Sheets',
  'Annual SIPP Report',
  'Partner Companies',
]
</script>

<template>
  <DashboardShell role-label="Coordinator" :nav-items="navItems">
    <p class="text-slate-500">
      Coordinator dashboard content goes here. Program-wide monitoring views
      get wired in starting Phase 3, with SIPP reporting in Phase 5.
    </p>
  </DashboardShell>
</template>

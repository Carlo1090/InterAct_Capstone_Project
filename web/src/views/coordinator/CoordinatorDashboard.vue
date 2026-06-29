<script setup>
import DashboardShell from '../../components/layout/DashboardShell.vue'

const navItems = [
  { label: 'Dashboard', to: '/coordinator' },
  { label: 'Daily Journal Activities', to: '/coordinator' },
  { label: 'Student Info Sheets', to: '/coordinator' },
  { label: 'Annual SIPP Report', to: '/coordinator' },
  { label: 'Partner Companies', to: '/coordinator' },
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

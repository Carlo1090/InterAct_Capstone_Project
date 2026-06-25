<script setup>
import DashboardShell from '../../components/layout/DashboardShell.vue'

const navItems = [
  'Dashboard',
  'Write Daily Journal',
  'My Journals',
  'My Journal Calendar',
  'Weekly Journals',
  'Student Info Sheet',
  'Generate Report',
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

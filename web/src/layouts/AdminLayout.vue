<script setup lang="ts">
import RoleLayout from '@/layouts/RoleLayout.vue'

const navItems = [
  { label: 'Dashboard', to: '/admin/dashboard' },
  { label: 'Users', to: '/admin/users' },
  { label: 'Departments', to: '/admin/departments' },
  { label: 'Batches', to: '/admin/batches' },
]
</script>

<template>
  <RoleLayout :nav-items="navItems" />
</template>

<script setup lang="ts">
import RoleLayout from '@/layouts/RoleLayout.vue'

const navItems = [{ label: 'Dashboard', to: '/coordinator/dashboard' }]
</script>

<template>
  <RoleLayout :nav-items="navItems" />
</template>

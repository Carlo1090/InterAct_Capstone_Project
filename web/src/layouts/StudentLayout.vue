<script setup lang="ts">
import RoleLayout from '@/layouts/RoleLayout.vue'

const navItems = [{ label: 'Dashboard', to: '/student/dashboard' }]
</script>

<template>
  <RoleLayout :nav-items="navItems" />
</template>

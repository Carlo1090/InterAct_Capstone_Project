<script setup>
import { RouterView } from 'vue-router'
import ConfirmHost from '@/components/ConfirmHost.vue'
</script>

<template>
  <RouterView />
  <!-- App-wide styled confirm/prompt dialog (replaces native window.confirm/prompt). -->
  <ConfirmHost />
</template>

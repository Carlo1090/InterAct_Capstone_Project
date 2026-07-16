<script setup lang="ts">
defineProps<{
  loading: boolean
  error: string
  retry?: () => void
}>()
</script>

<template>
  <p v-if="loading" class="text-sm text-slate-500">Loading...</p>
  <div v-else-if="error" class="flex items-center justify-between gap-3 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
    <span>{{ error }}</span>
    <button
      v-if="retry"
      type="button"
      class="shrink-0 rounded-md border border-red-300 px-3 py-1 text-xs font-semibold text-red-700 transition hover:bg-red-100"
      @click="retry"
    >
      Retry
    </button>
  </div>
  <slot v-else />
</template>

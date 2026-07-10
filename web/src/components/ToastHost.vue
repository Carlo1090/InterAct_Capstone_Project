<script setup lang="ts">
import { useToasts } from '@/lib/toast'

const toasts = useToasts()
</script>

<template>
  <div class="pointer-events-none fixed right-4 top-4 z-[100] flex flex-col gap-2">
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="pointer-events-auto rounded-md px-4 py-2 text-sm font-semibold text-white shadow-lg ring-1"
      :class="toast.type === 'success' ? 'bg-green-600 ring-green-700' : 'bg-red-600 ring-red-700'"
    >
      {{ toast.message }}
    </div>
  </div>
</template>

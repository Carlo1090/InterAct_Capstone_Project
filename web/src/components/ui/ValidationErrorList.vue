<script setup lang="ts">
import { computed } from 'vue'
import { friendlyFieldLabel } from '@/lib/fieldLabels'

const props = defineProps<{
  errors: Record<string, string[]>
}>()

const items = computed(() =>
  Object.entries(props.errors).map(([field, messages]) => ({
    field,
    label: friendlyFieldLabel(field),
    messages,
  })),
)
</script>

<template>
  <div v-if="items.length > 0" class="flex gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-3">
    <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
      <path
        fill-rule="evenodd"
        d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 8a1 1 0 100-2 1 1 0 000 2z"
        clip-rule="evenodd"
      />
    </svg>
    <ul class="space-y-1 text-sm text-red-700">
      <li v-for="item in items" :key="item.field">
        <span class="font-semibold">{{ item.label }}:</span> {{ item.messages.join(' ') }}
      </li>
    </ul>
  </div>
</template>

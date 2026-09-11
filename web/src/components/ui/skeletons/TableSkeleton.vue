<script setup lang="ts">
import Skeleton from '@/components/ui/Skeleton.vue'

withDefaults(
  defineProps<{
    headers: string[]
    rows?: number
  }>(),
  { rows: 6 }
)
</script>

<template>
  <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
    <table class="min-w-full divide-y divide-slate-200">
      <thead class="bg-slate-50">
        <tr>
          <th
            v-for="header in headers"
            :key="header"
            class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
          >
            {{ header }}
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <tr v-for="row in rows" :key="row">
          <td v-for="(header, index) in headers" :key="header" class="px-4 py-3">
            <Skeleton :width="index === 0 ? 'w-32' : 'w-20'" height="h-3" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

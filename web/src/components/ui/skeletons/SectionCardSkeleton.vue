<script setup lang="ts">
import Skeleton from '@/components/ui/Skeleton.vue'

withDefaults(
  defineProps<{
    variant?: 'progress' | 'activity' | 'details'
    rows?: number
  }>(),
  { variant: 'activity', rows: 3 }
)
</script>

<template>
  <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <div class="border-l-4 border-slate-200 pl-3">
      <Skeleton width="w-40" height="h-4" />
    </div>

    <div v-if="variant === 'progress'" class="mt-5 space-y-4">
      <div v-for="n in rows" :key="n">
        <div class="mb-2 flex justify-between">
          <Skeleton width="w-32" height="h-3" />
          <Skeleton width="w-8" height="h-3" />
        </div>
        <Skeleton height="h-2" rounded="rounded-full" />
      </div>
    </div>

    <div v-else-if="variant === 'details'" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <div v-for="n in rows" :key="n" class="border-b border-slate-100 pb-3">
        <Skeleton width="w-20" height="h-3" />
        <Skeleton width="w-32" height="h-4" class="mt-2" />
      </div>
    </div>

    <div v-else class="mt-4 divide-y divide-slate-100">
      <div v-for="n in rows" :key="n" class="flex gap-3 py-3">
        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-slate-200" />
        <div class="flex-1">
          <Skeleton width="w-3/4" height="h-3" />
          <Skeleton width="w-1/4" height="h-3" class="mt-2" />
        </div>
      </div>
    </div>
  </section>
</template>

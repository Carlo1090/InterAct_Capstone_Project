<script setup lang="ts">
import Skeleton from '@/components/ui/Skeleton.vue'

withDefaults(
  defineProps<{
    fields?: number
    placementFields?: number
  }>(),
  { fields: 6, placementFields: 3 }
)
</script>

<template>
  <div class="mt-5 space-y-5">
    <div class="flex items-center gap-4">
      <Skeleton width="w-16" height="h-16" rounded="rounded-full" />
      <div>
        <Skeleton width="w-40" height="h-4" />
        <Skeleton width="w-24" height="h-3" class="mt-2" />
      </div>
    </div>

    <div class="grid gap-4 rounded-md border border-slate-200 p-4 md:grid-cols-2">
      <div v-for="field in fields" :key="field">
        <Skeleton width="w-20" height="h-3" />
        <Skeleton width="w-32" height="h-3" class="mt-2" />
      </div>
    </div>

    <div class="rounded-md border border-slate-200 p-4">
      <Skeleton width="w-32" height="h-3" />
      <div class="mt-3 grid gap-4 md:grid-cols-3">
        <div v-for="field in placementFields" :key="field">
          <Skeleton width="w-16" height="h-3" />
          <Skeleton width="w-24" height="h-3" class="mt-2" />
        </div>
      </div>
    </div>
  </div>
</template>

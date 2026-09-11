<script setup lang="ts">
import Skeleton from '@/components/ui/Skeleton.vue'

withDefaults(defineProps<{ count?: number }>(), { count: 4 })
</script>

<template>
  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
    <article
      v-for="n in count"
      :key="n"
      class="overflow-hidden rounded-lg bg-white text-center shadow-sm ring-1 ring-slate-200"
    >
      <div class="h-1 bg-slate-200" />
      <div class="px-5 py-6">
        <Skeleton width="w-16" height="h-9" class="mx-auto" />
        <div class="mx-auto my-3 h-px w-10 bg-slate-200" />
        <Skeleton width="w-28" height="h-3" class="mx-auto" />
        <Skeleton width="w-20" height="h-3" class="mx-auto mt-2" />
      </div>
    </article>
  </div>
</template>

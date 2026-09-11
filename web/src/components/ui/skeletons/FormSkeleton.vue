<script setup lang="ts">
import Skeleton from '@/components/ui/Skeleton.vue'

withDefaults(
  defineProps<{
    sections?: number
    fieldsPerSection?: number
  }>(),
  { sections: 2, fieldsPerSection: 4 }
)
</script>

<template>
  <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
    <div v-for="section in sections" :key="section" class="rounded-lg bg-slate-50 p-5" :class="{ 'mt-4': section > 1 }">
      <Skeleton width="w-56" height="h-3" />
      <div class="mt-4 grid gap-4 md:grid-cols-2">
        <div v-for="field in fieldsPerSection" :key="field">
          <Skeleton width="w-24" height="h-3" />
          <Skeleton height="h-[38px]" rounded="rounded-md" class="mt-2" />
        </div>
      </div>
    </div>
  </div>
</template>

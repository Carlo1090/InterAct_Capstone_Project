<script setup lang="ts">
import { computed } from 'vue'
import type { InternDetail } from '@/types/api'

const props = defineProps<{ detail: InternDetail | null; isLoading: boolean; errorMessage?: string }>()
defineEmits<{ close: [] }>()

const initials = computed(() =>
  (props.detail?.name ?? '')
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

const formatDate = (value: string | null | undefined): string => {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
    <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-slate-950">Intern Details</h3>
        <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="$emit('close')">Close</button>
      </div>

      <p v-if="isLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
      <p v-else-if="errorMessage" class="mt-6 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>

      <div v-else-if="detail" class="mt-5 space-y-5">
        <div class="flex items-center gap-4">
          <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-lg font-bold text-white">
            <img v-if="detail.avatar_url" :src="detail.avatar_url" alt="Profile photo" class="h-full w-full object-cover" />
            <span v-else>{{ initials }}</span>
          </div>
          <div>
            <p class="text-base font-semibold text-slate-900">{{ detail.name }}</p>
            <p class="font-mono text-xs text-slate-400">{{ detail.student_id_number ?? 'No student ID on file' }}</p>
          </div>
        </div>

        <div class="grid gap-4 rounded-md border border-slate-200 p-4 md:grid-cols-2">
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Email</p>
            <p class="text-sm text-slate-800">{{ detail.email ?? '—' }}</p>
          </div>
          <div v-if="detail.username">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Username</p>
            <p class="text-sm text-slate-800">{{ detail.username }}</p>
          </div>
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Program</p>
            <p class="text-sm text-slate-800">{{ detail.program?.name ?? '—' }}</p>
          </div>
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Year Level</p>
            <p class="text-sm text-slate-800">{{ detail.profile?.year_level ?? '—' }}</p>
          </div>
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Contact Number</p>
            <p class="text-sm text-slate-800">{{ detail.profile?.contact_number ?? '—' }}</p>
          </div>
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Sex</p>
            <p class="text-sm text-slate-800">{{ detail.profile?.sex ?? '—' }}</p>
          </div>
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Date of Birth</p>
            <p class="text-sm text-slate-800">{{ formatDate(detail.profile?.date_of_birth) }}</p>
          </div>
          <div class="md:col-span-2">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Home Address</p>
            <p class="text-sm text-slate-800">{{ detail.profile?.home_address ?? '—' }}</p>
          </div>
        </div>

        <div class="rounded-md border border-slate-200 p-4">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Current Placement</p>
          <div v-if="detail.enrollment" class="mt-2 grid gap-4 md:grid-cols-3">
            <div>
              <p class="text-xs text-slate-400">Batch</p>
              <p class="text-sm text-slate-800">{{ detail.enrollment.batch?.name ?? '—' }}</p>
            </div>
            <div>
              <p class="text-xs text-slate-400">Company</p>
              <p class="text-sm text-slate-800">{{ detail.enrollment.company?.name ?? '—' }}</p>
            </div>
            <div v-if="detail.enrollment.supervisor">
              <p class="text-xs text-slate-400">Supervisor</p>
              <p class="text-sm text-slate-800">{{ detail.enrollment.supervisor.name }}</p>
            </div>
          </div>
          <p v-else class="mt-2 text-sm text-slate-400">Not currently enrolled.</p>
        </div>
      </div>
    </section>
  </div>
</template>

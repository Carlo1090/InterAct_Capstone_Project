<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { PaginatedResponse, Program, User, Batch } from '@/types/api'

const isLoading = ref(true)
const errorMessage = ref('')
const totalUsers = ref(0)
const totalBatches = ref(0)
const totalPrograms = ref(0)

const countResponse = <T>(payload: PaginatedResponse<T> | T[]): number => {
  return Array.isArray(payload) ? payload.length : payload.total ?? payload.data.length
}

const loadStats = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [users, batches, programs] = await Promise.all([
      api.get<PaginatedResponse<User>>('/api/admin/users'),
      api.get<PaginatedResponse<Batch>>('/api/admin/batches'),
      api.get<Program[]>('/api/admin/programs'),
    ])

    totalUsers.value = countResponse(users.data)
    totalBatches.value = countResponse(batches.data)
    totalPrograms.value = countResponse(programs.data)
  } catch {
    errorMessage.value = 'Unable to load dashboard stats.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadStats)
</script>

<template>
  <section>
    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ errorMessage }}
    </p>

    <div v-else class="grid gap-4 md:grid-cols-3">
      <article class="overflow-hidden rounded-lg bg-white text-center shadow-sm ring-1 ring-slate-200">
        <div class="h-1 bg-blue-600" />
        <div class="px-5 py-6">
          <p class="text-4xl font-extrabold text-slate-900">{{ totalUsers }}</p>
          <div class="mx-auto my-3 h-px w-10 bg-slate-200" />
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Total Users</p>
        </div>
      </article>
      <article class="overflow-hidden rounded-lg bg-white text-center shadow-sm ring-1 ring-slate-200">
        <div class="h-1 bg-green-600" />
        <div class="px-5 py-6">
          <p class="text-4xl font-extrabold text-slate-900">{{ totalBatches }}</p>
          <div class="mx-auto my-3 h-px w-10 bg-slate-200" />
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Total Batches</p>
        </div>
      </article>
      <article class="overflow-hidden rounded-lg bg-white text-center shadow-sm ring-1 ring-slate-200">
        <div class="h-1 bg-amber-500" />
        <div class="px-5 py-6">
          <p class="text-4xl font-extrabold text-slate-900">{{ totalPrograms }}</p>
          <div class="mx-auto my-3 h-px w-10 bg-slate-200" />
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Total Programs</p>
        </div>
      </article>
    </div>
  </section>
</template>

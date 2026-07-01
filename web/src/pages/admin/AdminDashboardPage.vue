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
    <h2 class="text-2xl font-bold text-slate-950">Admin Dashboard</h2>

    <p v-if="isLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="mt-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ errorMessage }}
    </p>

    <div v-else class="mt-6 grid gap-4 md:grid-cols-3">
      <article class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-sm font-medium text-slate-500">Total Users</p>
        <p class="mt-3 text-3xl font-bold text-slate-950">{{ totalUsers }}</p>
      </article>
      <article class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-sm font-medium text-slate-500">Total Batches</p>
        <p class="mt-3 text-3xl font-bold text-slate-950">{{ totalBatches }}</p>
      </article>
      <article class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-sm font-medium text-slate-500">Total Programs</p>
        <p class="mt-3 text-3xl font-bold text-slate-950">{{ totalPrograms }}</p>
      </article>
    </div>
  </section>
</template>

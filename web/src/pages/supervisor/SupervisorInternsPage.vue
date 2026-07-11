<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/lib/axios'
import type { SupervisorInternRow } from '@/types/api'

const router = useRouter()

const interns = ref<SupervisorInternRow[]>([])
const search = ref('')
const isLoading = ref(true)
const errorMessage = ref('')

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  try {
    const params: Record<string, string> = {}
    if (search.value) params.search = search.value
    const { data } = await api.get<{ interns: SupervisorInternRow[] }>('/api/supervisor/interns', { params })
    interns.value = data.interns
  } catch {
    errorMessage.value = 'Unable to load your interns.'
  } finally {
    isLoading.value = false
  }
}

const reviewStudent = () => {
  // Weekly journals are reviewed from the Journals page.
  router.push('/supervisor/journals')
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Interns assigned to you (via your company placements). Weekly-journal review counts are shown per intern.
    </div>

    <div class="flex flex-wrap gap-3">
      <input
        v-model="search"
        class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
        placeholder="Search students..."
        @keyup.enter="load"
      />
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" @click="load">
        Search
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Weekly Journals</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="interns.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="5">No interns assigned to you yet.</td>
          </tr>
          <tr v-for="intern in interns" :key="intern.student_id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ intern.name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ intern.student_id_number ?? '—' }}</p>
            </td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ intern.program || '—' }}</span>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ intern.company || '—' }}</td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-1.5 text-xs font-semibold">
                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ intern.pending_count }} pending</span>
                <span class="rounded-full bg-green-50 px-2 py-0.5 text-green-700">{{ intern.approved_count }} approved</span>
                <span v-if="intern.returned_count > 0" class="rounded-full bg-red-50 px-2 py-0.5 text-red-700">{{ intern.returned_count }} returned</span>
              </div>
            </td>
            <td class="px-4 py-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="reviewStudent">
                Review Journals
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

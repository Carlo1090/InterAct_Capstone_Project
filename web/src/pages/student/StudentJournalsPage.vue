<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/lib/axios'
import type { JournalEntrySummary, PaginatedResponse } from '@/types/api'

const router = useRouter()

const journals = ref<JournalEntrySummary[]>([])
const isLoading = ref(true)
const errorMessage = ref('')
const statusFilter = ref('')

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<PaginatedResponse<JournalEntrySummary>>('/api/student/journal-entries', {
      params: statusFilter.value ? { status: statusFilter.value } : {},
    })
    journals.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load your journals.'
  } finally {
    isLoading.value = false
  }
}

const viewEntry = (date: string) => {
  router.push({ path: '/student/write-journal', query: { date, view: '1' } })
}

const dayName = (date: string) => new Date(date).toLocaleDateString(undefined, { weekday: 'long' })

watch(statusFilter, load)
onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Daily journal entries only track <strong>submission status</strong>. Review and approval happen after the entries are compiled into your <strong>weekly journal</strong>.
    </div>

    <div class="flex flex-wrap gap-3">
      <select v-model="statusFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Status</option>
        <option value="submitted">Submitted</option>
        <option value="draft">Draft</option>
      </select>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Date</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Day</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Word Count</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="journals.length === 0">
            <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">No journal entries found.</td>
          </tr>
          <tr v-for="journal in journals" :key="journal.id">
            <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ journal.entry_date }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ dayName(journal.entry_date) }}</td>
            <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ journal.word_count }}</td>
            <td class="px-4 py-3 text-sm">
              <span
                class="rounded-full px-3 py-1 text-xs font-bold capitalize"
                :class="journal.status === 'submitted' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700'"
              >
                {{ journal.status }}
              </span>
            </td>
            <td class="px-4 py-3 text-sm">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                @click="viewEntry(journal.entry_date)"
              >
                View
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

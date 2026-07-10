<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { CoordinatorInfoSheetDetail, CoordinatorInfoSheetRow } from '@/types/api'

const students = ref<CoordinatorInfoSheetRow[]>([])
const search = ref('')
const isLoading = ref(true)
const errorMessage = ref('')

const detail = ref<CoordinatorInfoSheetDetail | null>(null)
const isDetailOpen = ref(false)
const isDetailLoading = ref(false)

const statusClass = (status: string | null): string => {
  if (status === 'submitted') return 'bg-green-50 text-green-700'
  if (status === 'draft') return 'bg-amber-50 text-amber-700'
  return 'bg-slate-100 text-slate-500'
}

const statusLabel = (status: string | null): string => {
  if (status === 'submitted') return 'Submitted'
  if (status === 'draft') return 'Draft'
  return 'Not Started'
}

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const params: Record<string, string> = {}
    if (search.value) params.search = search.value
    const { data } = await api.get<{ students: CoordinatorInfoSheetRow[] }>('/api/coordinator/info-sheets', { params })
    students.value = data.students
  } catch {
    errorMessage.value = 'Unable to load student info sheets.'
  } finally {
    isLoading.value = false
  }
}

const viewSheet = async (row: CoordinatorInfoSheetRow) => {
  isDetailOpen.value = true
  isDetailLoading.value = true
  detail.value = null

  try {
    const { data } = await api.get<CoordinatorInfoSheetDetail>(`/api/coordinator/info-sheets/${row.student_id}`)
    detail.value = data
  } catch {
    errorMessage.value = 'Unable to load this info sheet.'
    isDetailOpen.value = false
  } finally {
    isDetailLoading.value = false
  }
}

const closeDetail = () => {
  isDetailOpen.value = false
  detail.value = null
}

const sections = computed(() => {
  const sheet = detail.value?.sheet
  if (!sheet) return []
  return [
    { title: 'Personal Information', data: sheet.personal_info },
    { title: 'Academic Information', data: sheet.academic_info },
    { title: 'OJT Information', data: sheet.ojt_info },
  ].filter((section) => section.data && Object.keys(section.data).length > 0)
})

const labelize = (key: string): string => key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This list is <strong>read-only</strong> and scoped to your department's students.
    </div>

    <div class="flex flex-wrap gap-3">
      <input
        v-model="search"
        class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
        placeholder="Search student..."
        @keyup.enter="load"
      />
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" @click="load">
        Search
      </button>
      <!-- TODO(student-cleanup): info sheet PDF export (deferred to Student Page cleanup) -->
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
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Info Sheet</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="students.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="5">No students in your scope yet.</td>
          </tr>
          <tr v-for="row in students" :key="row.student_id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ row.name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ row.program || '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ row.company || '—' }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(row.submission_status)">{{ statusLabel(row.submission_status) }}</span>
            </td>
            <td class="px-4 py-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="viewSheet(row)">
                View
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Read-only detail modal -->
    <div v-if="isDetailOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">{{ detail?.student.name ?? 'Info Sheet' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeDetail">Close</button>
        </div>

        <p v-if="isDetailLoading" class="mt-5 text-sm text-slate-500">Loading...</p>
        <p v-else-if="!detail?.sheet" class="mt-5 rounded-md bg-slate-50 px-3 py-3 text-sm text-slate-500">
          This student has not started their information sheet yet.
        </p>

        <div v-else class="mt-5 space-y-5">
          <div v-for="section in sections" :key="section.title">
            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ section.title }}</h4>
            <dl class="mt-2 grid gap-x-6 gap-y-2 md:grid-cols-2">
              <div v-for="(value, key) in section.data" :key="key" class="border-b border-slate-100 pb-1">
                <dt class="text-xs text-slate-400">{{ labelize(String(key)) }}</dt>
                <dd class="text-sm text-slate-800">{{ value || '—' }}</dd>
              </div>
            </dl>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

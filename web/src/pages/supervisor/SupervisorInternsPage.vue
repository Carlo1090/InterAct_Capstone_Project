<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/lib/axios'
import InternDetailModal from '@/components/interns/InternDetailModal.vue'
import LoadStatus from '@/components/LoadStatus.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { InternDetail, SupervisorInternRow } from '@/types/api'

const router = useRouter()

const interns = ref<SupervisorInternRow[]>([])
const search = ref('')
const isLoading = ref(true)
const errorMessage = ref('')

const isInternModalOpen = ref(false)
const isLoadingIntern = ref(false)
const internDetail = ref<InternDetail | null>(null)
const internDetailError = ref('')

const viewIntern = async (studentId: number) => {
  isInternModalOpen.value = true
  isLoadingIntern.value = true
  internDetail.value = null
  internDetailError.value = ''

  try {
    const { data } = await api.get<InternDetail>(`/api/supervisor/interns/${studentId}`)
    internDetail.value = data
  } catch {
    internDetailError.value = 'Unable to load this student\'s details.'
  } finally {
    isLoadingIntern.value = false
  }
}

const closeInternModal = () => {
  isInternModalOpen.value = false
}

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
    <div class="rounded-xl border border-blue-100 bg-blue-50 p-6 text-sm text-blue-800">
      Interns assigned to you (via your company placements). Weekly-journal review counts are shown per intern.
    </div>

    <div class="mb-5 flex flex-wrap items-end gap-3">
      <label class="block w-full sm:w-auto">
        <span class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400">Search</span>
        <input
          v-model="search"
          class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm sm:w-auto sm:min-w-72"
          placeholder="Search students..."
          @keyup.enter="load"
        />
      </label>
      <button
        type="button"
        class="h-10 rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
        @click="load"
      >
        Search
      </button>
    </div>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <p v-if="interns.length === 0" class="rounded-xl bg-white px-6 py-8 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200/70">
        No interns assigned to you yet.
      </p>

      <template v-else>
        <!-- md and up: aligned table. `table-fixed` makes the colgroup binding. -->
        <div class="hidden overflow-x-auto rounded-xl bg-white px-6 shadow-sm ring-1 ring-slate-200/70 md:block">
          <table class="w-full table-fixed">
            <colgroup>
              <col />
              <col class="w-[120px]" />
              <col class="w-[220px]" />
              <col class="w-[200px]" />
              <col class="w-[230px]" />
            </colgroup>
            <thead>
              <tr class="text-xs font-medium uppercase tracking-wide text-slate-400">
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pl-0 pr-4 pt-4 text-left font-medium">Student</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Program</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Company</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Weekly Journals</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pl-4 pr-0 pt-4 text-right font-medium">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="intern in interns" :key="intern.student_id" class="transition-colors hover:bg-slate-50/70">
                <td class="py-3.5 pl-0 pr-4">
                  <p class="truncate text-sm font-medium text-slate-900">{{ intern.name }}</p>
                  <p class="truncate text-xs text-slate-400">{{ intern.student_id_number ?? '—' }}</p>
                </td>
                <td class="whitespace-nowrap px-4 py-3.5">
                  <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ intern.program || '—' }}</span>
                </td>
                <td class="px-4 py-3.5 text-sm text-slate-500">
                  <TooltipWrap :label="intern.company || 'No company'" placement="top" class="max-w-full">
                    <span class="block max-w-full truncate">{{ intern.company || '—' }}</span>
                  </TooltipWrap>
                </td>
                <td class="whitespace-nowrap px-4 py-3.5">
                  <div class="flex items-center gap-2 text-xs font-semibold">
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ intern.pending_count }} pending</span>
                    <span class="rounded-full bg-green-50 px-2 py-0.5 text-green-700">{{ intern.approved_count }} approved</span>
                    <span v-if="intern.returned_count > 0" class="rounded-full bg-red-50 px-2 py-0.5 text-red-700">
                      {{ intern.returned_count }} returned
                    </span>
                  </div>
                </td>
                <td class="whitespace-nowrap py-3.5 pl-4 pr-0">
                  <div class="flex justify-end gap-2">
                    <button
                      type="button"
                      class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                      @click="viewIntern(intern.student_id)"
                    >
                      View
                    </button>
                    <button
                      type="button"
                      class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-700"
                      @click="reviewStudent"
                    >
                      Review Journals
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Below md: one stacked block per intern, so nothing scrolls sideways. -->
        <ul class="divide-y divide-slate-100 rounded-xl bg-white px-6 shadow-sm ring-1 ring-slate-200/70 md:hidden">
          <li v-for="intern in interns" :key="intern.student_id" class="py-4">
            <div class="flex items-start justify-between gap-3">
              <p class="min-w-0 truncate text-sm font-medium text-slate-900">{{ intern.name }}</p>
              <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                {{ intern.program || '—' }}
              </span>
            </div>
            <p class="mt-1 truncate text-xs text-slate-500">{{ intern.company || '—' }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold">
              <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ intern.pending_count }} pending</span>
              <span class="rounded-full bg-green-50 px-2 py-0.5 text-green-700">{{ intern.approved_count }} approved</span>
              <span v-if="intern.returned_count > 0" class="rounded-full bg-red-50 px-2 py-0.5 text-red-700">
                {{ intern.returned_count }} returned
              </span>
            </div>
            <div class="mt-3 flex gap-2">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                @click="viewIntern(intern.student_id)"
              >
                View
              </button>
              <button
                type="button"
                class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-700"
                @click="reviewStudent"
              >
                Review Journals
              </button>
            </div>
          </li>
        </ul>
      </template>
    </LoadStatus>

    <InternDetailModal
      v-if="isInternModalOpen"
      :detail="internDetail"
      :is-loading="isLoadingIntern"
      :error-message="internDetailError"
      @close="closeInternModal"
    />
  </section>
</template>

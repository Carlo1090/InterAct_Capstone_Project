<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/lib/axios'
import InternDetailModal from '@/components/interns/InternDetailModal.vue'
import LoadStatus from '@/components/LoadStatus.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { InternDetail, SupervisorInternRow } from '@/types/api'

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

onMounted(load)
</script>
<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Interns assigned to you (via your company placements). <strong>Journals</strong> opens one intern's full notebook —
      every week they have handed in, front to back — where you can also approve or return a week.
    </div>

    <div class="flex flex-wrap items-end gap-3">
      <label class="block w-full sm:w-auto">
        <span class="mb-1.5 block text-xs font-bold text-slate-600">Search</span>
        <input
          v-model="search"
          class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm sm:w-auto sm:min-w-72"
          placeholder="Search students..."
          @keyup.enter="load"
        />
      </label>
      <button
        type="button"
        class="h-10 rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
        @click="load"
      >
        Search
      </button>
    </div>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <!--
        md and up: aligned table. Fixed columns are sized to their longest real
        value (the two action buttons, the three count pills) and Student carries
        no width, so it takes the remainder — no `min-w` floor, which would push
        the Action column behind a scrollbar.
      -->
      <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 md:block">
        <table class="w-full table-fixed divide-y divide-slate-200">
          <colgroup>
            <col />
            <col class="w-[110px]" />
            <col class="w-[180px]" />
            <col class="w-[250px]" />
            <col class="w-[210px]" />
          </colgroup>
          <thead class="bg-slate-50">
            <tr>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Weekly Journals</th>
              <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="interns.length === 0">
              <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">No interns assigned to you yet.</td>
            </tr>
            <tr v-for="intern in interns" :key="intern.student_id" class="transition-colors hover:bg-slate-50/70">
              <td class="px-4 py-3">
                <p class="truncate text-sm font-semibold text-slate-900">{{ intern.name }}</p>
                <p class="truncate font-mono text-xs text-slate-400">{{ intern.student_id_number ?? '—' }}</p>
              </td>
              <td class="whitespace-nowrap px-4 py-3">
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ intern.program || '—' }}</span>
              </td>
              <td class="px-4 py-3 text-sm text-slate-500">
                <TooltipWrap :label="intern.company || 'No company'" placement="top" class="max-w-full">
                  <span class="block max-w-full truncate">{{ intern.company || '—' }}</span>
                </TooltipWrap>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                  <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ intern.pending_count }} pending</span>
                  <span class="rounded-full bg-green-50 px-2 py-0.5 text-green-700">{{ intern.approved_count }} approved</span>
                  <span v-if="intern.returned_count > 0" class="rounded-full bg-red-50 px-2 py-0.5 text-red-700">
                    {{ intern.returned_count }} returned
                  </span>
                </div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    @click="viewIntern(intern.student_id)"
                  >
                    View
                  </button>
                  <TooltipWrap :label="`Open ${intern.name}'s journal notebook`" placement="top" align="end">
                    <RouterLink
                      :to="`/supervisor/interns/${intern.student_id}/journals`"
                      :aria-label="`Open ${intern.name}'s journal notebook`"
                      class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 text-slate-400">
                        <path
                          d="M4 5.5A1.5 1.5 0 0 1 5.5 4H18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5.5A1.5 1.5 0 0 1 4 18.5v-13ZM8 4v16"
                          stroke="currentColor"
                          stroke-width="1.7"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                      Journals
                    </RouterLink>
                  </TooltipWrap>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Below md: one stacked block per intern, so nothing scrolls sideways. -->
      <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 md:hidden">
        <li v-if="interns.length === 0" class="py-6 text-center text-sm text-slate-500">No interns assigned to you yet.</li>
        <li v-for="intern in interns" :key="intern.student_id" class="py-4">
          <div class="flex items-start justify-between gap-3">
            <p class="min-w-0 truncate text-sm font-semibold text-slate-900">{{ intern.name }}</p>
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
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="viewIntern(intern.student_id)"
            >
              View
            </button>
            <RouterLink
              :to="`/supervisor/interns/${intern.student_id}/journals`"
              class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 text-slate-400">
                <path
                  d="M4 5.5A1.5 1.5 0 0 1 5.5 4H18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5.5A1.5 1.5 0 0 1 4 18.5v-13ZM8 4v16"
                  stroke="currentColor"
                  stroke-width="1.7"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
              Journals
            </RouterLink>
          </div>
        </li>
      </ul>
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

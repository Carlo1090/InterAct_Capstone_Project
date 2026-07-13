<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast, confirmAction } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import WeeklyJournalPaperView from '@/components/journal/WeeklyJournalPaperView.vue'
import type { SupervisorJournalDetail, SupervisorJournalRow, SupervisorReviewStatus } from '@/types/api'

const tabs: { key: SupervisorReviewStatus; label: string }[] = [
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'returned', label: 'Returned' },
]

const activeStatus = ref<SupervisorReviewStatus>('pending')
const logs = ref<SupervisorJournalRow[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const isDetailOpen = ref(false)
const isDetailLoading = ref(false)
const detail = ref<SupervisorJournalDetail | null>(null)

const showReturnForm = ref(false)
const returnComment = ref('')
const isSubmitting = ref(false)
const reviewError = ref('')

const statusClass = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'bg-green-50 text-green-700'
  if (status === 'returned') return 'bg-red-50 text-red-700'
  return 'bg-amber-50 text-amber-700'
}

const formatDateTime = (iso: string | null): string => (iso ? new Date(iso).toLocaleString() : '—')

const downloadPdf = () => {
  if (!detail.value) return
  window.open(`/api/supervisor/journals/${detail.value.id}/pdf`, '_blank')
}

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.get<{ status: SupervisorReviewStatus; logs: SupervisorJournalRow[] }>('/api/supervisor/journals', {
      params: { status: activeStatus.value },
    })
    logs.value = data.logs
  } catch {
    errorMessage.value = 'Unable to load weekly journals.'
  } finally {
    isLoading.value = false
  }
}

const selectTab = (status: SupervisorReviewStatus) => {
  if (status === activeStatus.value) return
  activeStatus.value = status
  load()
}

const openReview = async (row: SupervisorJournalRow) => {
  isDetailOpen.value = true
  isDetailLoading.value = true
  detail.value = null
  showReturnForm.value = false
  returnComment.value = ''
  reviewError.value = ''

  try {
    const { data } = await api.get<SupervisorJournalDetail>(`/api/supervisor/journals/${row.id}`)
    detail.value = data
  } catch {
    reviewError.value = 'Unable to load this weekly journal.'
  } finally {
    isDetailLoading.value = false
  }
}

const closeDetail = () => {
  isDetailOpen.value = false
  detail.value = null
}

const approve = async () => {
  if (!detail.value) return
  isSubmitting.value = true
  reviewError.value = ''
  try {
    await api.post(`/api/supervisor/journals/${detail.value.id}/approve`)
    showToast('Weekly journal approved.')
    closeDetail()
    await load()
  } catch (error) {
    reviewError.value = axios.isAxiosError(error) ? error.response?.data?.message ?? 'Unable to approve.' : 'Unable to approve.'
  } finally {
    isSubmitting.value = false
  }
}

const submitReturn = async () => {
  if (!detail.value) return
  reviewError.value = ''

  if (!returnComment.value.trim()) {
    reviewError.value = 'Please explain what the student needs to fix.'
    return
  }
  if (!confirmAction('Return this weekly journal to the student for revision?')) return

  isSubmitting.value = true
  try {
    await api.post(`/api/supervisor/journals/${detail.value.id}/return`, { supervisor_comment: returnComment.value })
    showToast('Weekly journal returned to the student.')
    closeDetail()
    await load()
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      reviewError.value = error.response.data.errors?.supervisor_comment?.[0] ?? 'Please fix the errors.'
    } else {
      reviewError.value = 'Unable to return this journal.'
    }
  } finally {
    isSubmitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Review your interns' <strong>weekly narrative journals</strong>. Approve a journal, or return it with a comment so
      the student can revise it.
    </div>

    <div class="flex gap-1 border-b border-slate-200">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="border-b-2 px-4 py-2 text-sm font-semibold transition"
        :class="tab.key === activeStatus ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
        @click="selectTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Week</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Daily Entries</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Submitted</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="logs.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="5">No {{ activeStatus }} weekly journals.</td>
          </tr>
          <tr v-for="log in logs" :key="log.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ log.student_name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ log.student_id_number ?? '—' }}</p>
            </td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ log.week_start }} – {{ log.week_end }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ log.entries_count }} entries</span>
            </td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ formatDateTime(log.submitted_at) }}</td>
            <td class="px-4 py-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="openReview(log)">
                Review
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Review modal -->
    <div v-if="isDetailOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-3xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-950">{{ detail?.student.name ?? 'Weekly Journal' }}</h3>
            <p v-if="detail" class="mt-0.5 text-xs text-slate-500">Week {{ detail.week_start }} – {{ detail.week_end }}</p>
          </div>
          <div class="flex items-center gap-3">
            <button v-if="detail" type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="downloadPdf">Download PDF</button>
            <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeDetail">Close</button>
          </div>
        </div>

        <p v-if="isDetailLoading" class="mt-5 text-sm text-slate-500">Loading...</p>

        <div v-else-if="detail" class="mt-5 space-y-5">
          <div class="flex items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-bold capitalize" :class="statusClass(detail.status)">{{ detail.status }}</span>
            <span class="text-xs text-slate-400">Submitted {{ formatDateTime(detail.submitted_at) }}</span>
          </div>

          <!-- Document preview: the weekly narrative rendered as the same
               typed document the PDF produces. Status/actions stay out here
               in the page chrome. -->
          <div class="rounded-md bg-slate-100 p-4 sm:p-6">
            <WeeklyJournalPaperView
              :narrative="detail.narrative"
              :student-name="detail.student.name"
              :week-start="detail.week_start"
              :week-end="detail.week_end"
            />
          </div>

          <div v-if="detail.supervisor_comment">
            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">Your Previous Comment</h4>
            <p class="mt-2 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ detail.supervisor_comment }}</p>
          </div>

          <details class="rounded-md border border-slate-200">
            <summary class="cursor-pointer px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-500 transition hover:text-slate-700">
              Daily Entries This Week ({{ detail.daily_entries.length }})
            </summary>
            <div class="border-t border-slate-100 p-3">
              <div v-if="detail.daily_entries.length === 0" class="text-sm text-slate-400">No daily entries for this week.</div>
              <div v-else class="space-y-2">
                <div v-for="entry in detail.daily_entries" :key="entry.entry_date" class="rounded-md border border-slate-200 p-3">
                  <div class="flex items-center justify-between">
                    <p class="font-mono text-xs font-semibold text-slate-600">{{ entry.entry_date }}</p>
                    <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ entry.status }}</span>
                  </div>
                  <p v-for="(value, key) in entry.content" :key="key" class="mt-1 text-xs text-slate-600">
                    <span class="font-semibold text-slate-500">{{ key }}:</span> {{ value }}
                  </p>
                </div>
              </div>
            </div>
          </details>

          <p v-if="reviewError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ reviewError }}</p>

          <!-- Review actions -->
          <div v-if="detail.reviewable" class="border-t border-slate-200 pt-4">
            <div v-if="!showReturnForm" class="flex justify-end gap-3">
              <button
                type="button"
                class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 disabled:opacity-50"
                :disabled="isSubmitting"
                @click="showReturnForm = true"
              >
                Return with Comment
              </button>
              <button
                type="button"
                class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                :disabled="isSubmitting"
                @click="approve"
              >
                {{ isSubmitting ? 'Working...' : 'Approve' }}
              </button>
            </div>

            <div v-else class="space-y-3">
              <label class="block">
                <span class="text-xs font-bold text-slate-600">Comment (what should the student fix?)</span>
                <textarea
                  v-model="returnComment"
                  rows="3"
                  maxlength="2000"
                  class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                  placeholder="Explain what needs revision..."
                ></textarea>
              </label>
              <div class="flex justify-end gap-3">
                <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="showReturnForm = false">
                  Cancel
                </button>
                <button
                  type="button"
                  class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                  :disabled="isSubmitting"
                  @click="submitReturn"
                >
                  {{ isSubmitting ? 'Returning...' : 'Return Journal' }}
                </button>
              </div>
            </div>
          </div>

          <p v-else class="border-t border-slate-200 pt-4 text-sm text-slate-500">
            This journal was reviewed {{ formatDateTime(detail.reviewed_at) }} and can no longer be changed.
          </p>
        </div>
      </section>
    </div>
  </section>
</template>

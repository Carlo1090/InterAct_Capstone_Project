<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import LoadStatus from '@/components/LoadStatus.vue'
import ToastHost from '@/components/ToastHost.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'

/**
 * The coordinator's own weekly-journal review, for batches running under the
 * `coordinator` OJT type — the cohorts with no company supervisor, where the
 * coordinator gives the verdict themselves.
 *
 * Deliberately separate from Weekly Journals, which stays read-only monitoring
 * across every batch in scope. That page answers "how is my department doing?";
 * this one answers "what is waiting on me?" and is the only coordinator surface
 * that writes a verdict — supervisor-supported batches are reviewed by the
 * company and never appear here.
 *
 * Two cuts of the same data, matching the supervisor's own pair: the QUEUE is
 * one status at a time across every intern, and INTERNS is the index into each
 * student's whole notebook. Both hand off to the shared notebook page, which
 * carries the Approve / Return actions — there is deliberately no second review
 * modal here, because the notebook opens on the first week still awaiting review
 * and is therefore already the fast path.
 */

type ReviewStatus = 'pending' | 'approved' | 'returned'

type QueueRow = {
  id: number
  student_id: number
  student_name: string
  student_id_number: string | null
  batch: string
  week_start: string
  week_end: string
  status: ReviewStatus
  submitted_at: string | null
  entries_count: number
}

type InternRow = {
  student_id: number
  student_name: string
  student_id_number: string | null
  batch: string
  company: string
  enrollment_status: string | null
  pending: number
  approved: number
  returned: number
}

const tab = ref<'queue' | 'interns'>('queue')
const status = ref<ReviewStatus>('pending')

const rows = ref<QueueRow[]>([])
const interns = ref<InternRow[]>([])
const counts = ref<Record<ReviewStatus, number>>({ pending: 0, approved: 0, returned: 0 })
// How many coordinator-centered batches exist at all, which is a different
// question from whether anyone is enrolled on them — the empty state below has
// to tell those two apart or it tells the coordinator they never made one.
const centeredBatches = ref(0)

const isLoading = ref(true)
const errorMessage = ref('')

const statusTabs: { key: ReviewStatus; label: string }[] = [
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'returned', label: 'Returned' },
]

/**
 * `week_start`/`week_end` are `date`-cast columns — parsing and re-formatting
 * them lands a day earlier once the app runs in Asia/Manila, so slice instead.
 */
const dateOnly = (value: string | null | undefined): string => value?.trim().slice(0, 10) || '—'

/** `submitted_at` is a genuine instant carrying a marker, so parsing is correct. */
const formatInstant = (iso: string | null): string => {
  if (!iso) return '—'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'

  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

const statusClass = (value: ReviewStatus): string => {
  if (value === 'approved') return 'bg-green-50 text-green-700'
  if (value === 'returned') return 'bg-red-50 text-red-700'

  return 'bg-amber-50 text-amber-700'
}

const statusLabel = (value: ReviewStatus): string => {
  if (value === 'approved') return 'Approved'
  if (value === 'returned') return 'Returned'

  return 'Pending Review'
}

const notebookPath = (studentId: number): string => `/coordinator/journal-review/interns/${studentId}`

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [queue, internList] = await Promise.all([
      api.get<{
        status: ReviewStatus
        logs: QueueRow[]
        counts: Record<ReviewStatus, number>
        centered_batches: number
      }>(
        '/api/coordinator/journal-review',
        { params: { status: status.value } },
      ),
      api.get<{ interns: InternRow[] }>('/api/coordinator/journal-review/interns'),
    ])

    rows.value = queue.data.logs
    counts.value = queue.data.counts
    centeredBatches.value = queue.data.centered_batches
    interns.value = internList.data.interns
  } catch (error) {
    errorMessage.value = categorizeError(error, 'The review queue could not be loaded.').message
  } finally {
    isLoading.value = false
  }
}

const selectStatus = async (next: ReviewStatus) => {
  if (status.value === next) return
  status.value = next
  await load()
}

const hasAnyWork = computed(() => interns.value.length > 0)
const hasCenteredBatch = computed(() => centeredBatches.value > 0)

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <p class="text-sm text-slate-500">
      Review the <strong class="text-slate-700">weekly narrative journals</strong> of your coordinator-centered batches.
      Approve a journal, or return it with a comment so the intern can revise and resubmit.
    </p>

    <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3">
      <p class="text-sm text-blue-800">
        Only batches set to <strong>Coordinator-centered</strong> appear here. Your supervisor-supported batches are
        reviewed by the company &mdash; you can still read them, unchanged, under Weekly Journals.
      </p>
    </div>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <p
        v-if="!hasAnyWork"
        class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600"
      >
        <template v-if="hasCenteredBatch">
          You have {{ centeredBatches }} coordinator-centered
          batch{{ centeredBatches === 1 ? '' : 'es' }}, but no interns are enrolled on
          {{ centeredBatches === 1 ? 'it' : 'them' }} yet. Enrol students and their weekly journals will come here for
          your approval.
        </template>
        <template v-else>
          None of your batches is set to Coordinator-centered yet. Set a batch's OJT Type when you create it and its
          interns' weekly journals will come here for your approval.
        </template>
      </p>

      <template v-else>
        <!-- Queue vs Interns: one status across everyone, or one intern end to end. -->
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="rounded-full border px-4 py-1.5 text-sm font-semibold transition"
            :class="tab === 'queue' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'"
            @click="tab = 'queue'"
          >
            Review Queue
          </button>
          <button
            type="button"
            class="rounded-full border px-4 py-1.5 text-sm font-semibold transition"
            :class="tab === 'interns' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'"
            @click="tab = 'interns'"
          >
            Interns
            <span class="ml-1 text-slate-400">{{ interns.length }}</span>
          </button>
        </div>

        <template v-if="tab === 'queue'">
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-for="option in statusTabs"
              :key="option.key"
              type="button"
              class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-semibold transition"
              :class="status === option.key ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'"
              @click="selectStatus(option.key)"
            >
              {{ option.label }}
              <span
                class="rounded-full px-2 text-xs font-bold tabular-nums"
                :class="status === option.key ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ counts[option.key] }}
              </span>
            </button>
          </div>

          <!-- md and up: aligned table. -->
          <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 md:block">
            <table class="w-full table-fixed divide-y divide-slate-200">
              <!--
                Student is PINNED and Batch absorbs the slack, not the other way
                round: names are short and predictable, batch names are not.
              -->
              <colgroup>
                <col style="width: 230px" />
                <col />
                <col style="width: 190px" />
                <col style="width: 90px" />
                <col style="width: 140px" />
                <col style="width: 150px" />
              </colgroup>
              <thead class="bg-slate-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Batch</th>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Week</th>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Entries</th>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Submitted</th>
                  <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="rows.length === 0">
                  <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">
                    Nothing {{ status === 'pending' ? 'waiting for review' : status }} right now.
                  </td>
                </tr>
                <tr v-for="row in rows" :key="row.id">
                  <td class="px-4 py-3">
                    <TooltipWrap :label="row.student_name" class="max-w-full">
                      <span class="block max-w-full truncate text-sm font-semibold text-slate-900">{{ row.student_name }}</span>
                    </TooltipWrap>
                    <p class="text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
                  </td>
                  <td class="px-4 py-3">
                    <TooltipWrap :label="row.batch" class="max-w-full">
                      <span class="block max-w-full truncate text-sm text-slate-700">{{ row.batch }}</span>
                    </TooltipWrap>
                  </td>
                  <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700">
                    {{ dateOnly(row.week_start) }} &ndash; {{ dateOnly(row.week_end) }}
                  </td>
                  <td class="px-4 py-3 text-sm tabular-nums text-slate-500">{{ row.entries_count }}</td>
                  <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-500">{{ formatInstant(row.submitted_at) }}</td>
                  <td class="px-4 py-3">
                    <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                      <RouterLink
                        :to="notebookPath(row.student_id)"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                      >
                        Open Notebook
                      </RouterLink>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Below md: one stacked card per journal, so nothing scrolls sideways. -->
          <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 md:hidden">
            <li v-if="rows.length === 0" class="py-6 text-center text-sm text-slate-500">
              Nothing {{ status === 'pending' ? 'waiting for review' : status }} right now.
            </li>
            <li v-for="row in rows" :key="row.id" class="space-y-2 py-4">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-slate-900">{{ row.student_name }}</p>
                  <p class="text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
                </div>
                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(row.status)">
                  {{ statusLabel(row.status) }}
                </span>
              </div>
              <p class="text-sm text-slate-700">{{ row.batch }}</p>
              <p class="text-sm text-slate-500">
                {{ dateOnly(row.week_start) }} &ndash; {{ dateOnly(row.week_end) }} &middot; {{ row.entries_count }} entries
              </p>
              <p class="text-xs text-slate-400">Submitted {{ formatInstant(row.submitted_at) }}</p>
              <RouterLink
                :to="notebookPath(row.student_id)"
                class="inline-block rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
              >
                Open Notebook
              </RouterLink>
            </li>
          </ul>
        </template>

        <template v-else>
          <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 md:block">
            <table class="w-full table-fixed divide-y divide-slate-200">
              <!--
                Company is the FLEXIBLE column and the other four are trimmed to
                fit around it: company names are the longest value in the table,
                so giving the leftover to anything else leaves the longest text
                in the narrowest cell.
              -->
              <colgroup>
                <col style="width: 200px" />
                <col style="width: 180px" />
                <col />
                <col style="width: 215px" />
                <col style="width: 120px" />
              </colgroup>
              <thead class="bg-slate-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Batch</th>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Journals</th>
                  <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="interns.length === 0">
                  <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="5">No interns yet.</td>
                </tr>
                <tr v-for="intern in interns" :key="intern.student_id">
                  <td class="px-4 py-3">
                    <TooltipWrap :label="intern.student_name" class="max-w-full">
                      <span class="block max-w-full truncate text-sm font-semibold text-slate-900">{{ intern.student_name }}</span>
                    </TooltipWrap>
                    <p class="text-xs text-slate-400">{{ intern.student_id_number ?? '—' }}</p>
                  </td>
                  <td class="px-4 py-3">
                    <TooltipWrap :label="intern.batch" class="max-w-full">
                      <span class="block max-w-full truncate text-sm text-slate-700">{{ intern.batch }}</span>
                    </TooltipWrap>
                  </td>
                  <td class="px-4 py-3">
                    <TooltipWrap :label="intern.company || 'No company'" class="max-w-full">
                      <span class="block max-w-full truncate text-sm text-slate-700">{{ intern.company || '—' }}</span>
                    </TooltipWrap>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1.5">
                      <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700">
                        {{ intern.pending }} pending
                      </span>
                      <span class="rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-bold text-green-700">
                        {{ intern.approved }} approved
                      </span>
                      <span class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700">
                        {{ intern.returned }} returned
                      </span>
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                      <RouterLink
                        :to="notebookPath(intern.student_id)"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                      >
                        Journals
                      </RouterLink>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 md:hidden">
            <li v-if="interns.length === 0" class="py-6 text-center text-sm text-slate-500">No interns yet.</li>
            <li v-for="intern in interns" :key="intern.student_id" class="space-y-2 py-4">
              <div>
                <p class="truncate text-sm font-semibold text-slate-900">{{ intern.student_name }}</p>
                <p class="text-xs text-slate-400">{{ intern.student_id_number ?? '—' }}</p>
              </div>
              <p class="text-sm text-slate-700">{{ intern.batch }}</p>
              <p class="text-sm text-slate-500">{{ intern.company || '—' }}</p>
              <div class="flex flex-wrap gap-1.5">
                <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700">
                  {{ intern.pending }} pending
                </span>
                <span class="rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-bold text-green-700">
                  {{ intern.approved }} approved
                </span>
                <span class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700">
                  {{ intern.returned }} returned
                </span>
              </div>
              <RouterLink
                :to="notebookPath(intern.student_id)"
                class="inline-block rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
              >
                Journals
              </RouterLink>
            </li>
          </ul>
        </template>
      </template>
    </LoadStatus>
  </section>
</template>

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
 *
 * ONE FILTER BAR SERVES BOTH TABS. Batch and company narrow the queue and the
 * intern index identically, so switching tabs never silently changes what is
 * being looked at; status is the queue's own control and stays with it,
 * defaulting to Pending because "what is waiting on me" is the reason to open
 * this page.
 */

type ReviewStatus = 'pending' | 'approved' | 'returned'

type FilterOption = {
  id: number
  name: string
}

type QueueRow = {
  id: number
  student_id: number
  student_name: string
  student_id_number: string | null
  batch: string
  company: string
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

// Empty string rather than null so the `<select>`'s "All …" option binds
// cleanly; converted to a real absent param in `queryParams` below.
const batchId = ref<number | ''>('')
const companyId = ref<number | ''>('')

const rows = ref<QueueRow[]>([])
const interns = ref<InternRow[]>([])
const counts = ref<Record<ReviewStatus, number>>({ pending: 0, approved: 0, returned: 0 })
const batchOptions = ref<FilterOption[]>([])
const companyOptions = ref<FilterOption[]>([])
// How many coordinator-centered batches exist at all, which is a different
// question from whether anyone is enrolled on them — the empty state below has
// to tell those two apart or it tells the coordinator they never made one.
const centeredBatches = ref(0)

// TWO loading flags, and the split is load-bearing rather than tidiness. The
// filter bar and the tab strip live INSIDE <LoadStatus>, because whether to
// show them at all depends on data that has to arrive first. So if every
// filter change flipped `isLoading`, changing a filter would replace the whole
// section with a spinner, unmount the very `<select>` that was just used, and
// drop keyboard focus — the control would vanish under the pointer mid-gesture.
// `isLoading` is therefore the FIRST load only; every later fetch sets
// `isRefreshing`, which keeps the content mounted, dims it and disables the
// controls until the answer lands.
const isLoading = ref(true)
const isRefreshing = ref(false)
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

const hasFilters = computed(() => batchId.value !== '' || companyId.value !== '')

/** Only send a filter that is actually set — a blank one is not a filter. */
const queryParams = computed(() => {
  const params: Record<string, string | number> = {}
  if (batchId.value !== '') params.batch_id = batchId.value
  if (companyId.value !== '') params.company_id = companyId.value

  return params
})

// Every fetch after the first is a refresh, so a slower earlier request cannot
// paint over a newer one when two filters are changed in quick succession.
let requestToken = 0

const load = async (initial = false) => {
  const token = ++requestToken
  if (initial) isLoading.value = true
  else isRefreshing.value = true
  errorMessage.value = ''

  try {
    const [queue, internList] = await Promise.all([
      api.get<{
        status: ReviewStatus
        logs: QueueRow[]
        counts: Record<ReviewStatus, number>
        filters: { batches: FilterOption[]; companies: FilterOption[] }
        centered_batches: number
      }>(
        '/api/coordinator/journal-review',
        { params: { status: status.value, ...queryParams.value } },
      ),
      api.get<{ interns: InternRow[] }>(
        '/api/coordinator/journal-review/interns',
        { params: queryParams.value },
      ),
    ])

    if (token !== requestToken) return

    rows.value = queue.data.logs
    counts.value = queue.data.counts
    centeredBatches.value = queue.data.centered_batches
    // The options come from the UNFILTERED set server-side, so they stay put
    // as filters are applied — a dropdown that narrows to its own selection
    // cannot be changed without first clearing it.
    batchOptions.value = queue.data.filters.batches
    companyOptions.value = queue.data.filters.companies
    interns.value = internList.data.interns
  } catch (error) {
    if (token !== requestToken) return
    errorMessage.value = categorizeError(error, 'The review queue could not be loaded.').message
  } finally {
    if (token === requestToken) {
      isLoading.value = false
      isRefreshing.value = false
    }
  }
}

/** LoadStatus's Retry button hands this straight to `load`, so keep it unary. */
const reload = () => load()

const selectStatus = async (next: ReviewStatus) => {
  if (status.value === next) return
  status.value = next
  await load()
}

const clearFilters = async () => {
  if (!hasFilters.value) return
  batchId.value = ''
  companyId.value = ''
  await load()
}

const hasAnyWork = computed(() => interns.value.length > 0 || hasFilters.value)
const hasCenteredBatch = computed(() => centeredBatches.value > 0)

onMounted(() => load(true))
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

    <!--
      `isLoading` is the FIRST load only — see the two flags in the script. A
      refresh dims the content in place instead of unmounting it, so the filter
      that triggered it survives the round trip.
    -->
    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="reload">
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

      <!--
        `space-y-5` is on the <section>, and this fragment's children are its
        direct children, so the wrapper below would break the page rhythm — it
        is deliberately a <template> with the dimming applied per block instead.
      -->
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

        <!--
          Batch and company narrow BOTH tabs, so they sit above the tab-specific
          status pills rather than inside the queue. Each reloads on change —
          there is no Apply button anywhere else in the coordinator section.

          `load()` is CALLED, not passed: `@change="load"` would hand the change
          Event straight into its `initial` parameter, which is truthy, and every
          filter change would blank the page into the first-load spinner.

          A NATIVE `<select>` IS AS WIDE AS ITS WIDEST OPTION and `flex-wrap`
          cannot shrink it below that, so a long company name (the real one here
          is 46 characters) pushed this row 8px past a 390px phone. `w-full`
          below `sm` makes each control the width of the column instead;
          `max-w-full` keeps the intrinsic width capped at every larger size.
        -->
        <div class="flex flex-wrap items-end gap-4">
          <label class="block w-full min-w-0 sm:w-auto">
            <span class="text-xs font-bold text-slate-600">Batch</span>
            <select
              v-model="batchId"
              :disabled="isRefreshing"
              class="mt-1 block w-full max-w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:opacity-60 sm:w-auto"
              @change="load()"
            >
              <option value="">All batches</option>
              <option v-for="option in batchOptions" :key="option.id" :value="option.id">{{ option.name }}</option>
            </select>
          </label>

          <label class="block w-full min-w-0 sm:w-auto">
            <span class="text-xs font-bold text-slate-600">Company</span>
            <select
              v-model="companyId"
              :disabled="isRefreshing"
              class="mt-1 block w-full max-w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:opacity-60 sm:w-auto"
              @change="load()"
            >
              <option value="">All companies</option>
              <option v-for="option in companyOptions" :key="option.id" :value="option.id">{{ option.name }}</option>
            </select>
          </label>

          <button
            v-if="hasFilters"
            type="button"
            :disabled="isRefreshing"
            class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-60"
            @click="clearFilters"
          >
            Clear filters
          </button>

          <!--
            Politeness matters more than the spinner here: the content stays on
            screen during a refresh, so a screen reader needs telling that the
            numbers below are about to change.
          -->
          <p v-if="isRefreshing" class="text-sm text-slate-400" role="status" aria-live="polite">Updating&hellip;</p>
        </div>

        <template v-if="tab === 'queue'">
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-for="option in statusTabs"
              :key="option.key"
              type="button"
              :disabled="isRefreshing"
              class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-semibold transition disabled:opacity-60"
              :class="status === option.key ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'"
              @click="selectStatus(option.key)"
            >
              {{ option.label }}
              <!--
                The counts come from the SAME filtered query the table does, so
                a pill never promises rows the current batch/company filter
                would hide.
              -->
              <span
                class="rounded-full px-2 text-xs font-bold tabular-nums"
                :class="status === option.key ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ counts[option.key] }}
              </span>
            </button>
          </div>

          <!-- md and up: aligned table. -->
          <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 transition-opacity md:block" :class="{ 'opacity-60': isRefreshing }">
            <table class="w-full table-fixed divide-y divide-slate-200">
              <!--
                Student is PINNED and Batch absorbs the slack, not the other way
                round: names are short and predictable, batch names are not.
                Company rides UNDER the batch name rather than taking a seventh
                column, which would push this table into horizontal scroll.
              -->
              <!--
                WEEK MUST BE >= 180px. It renders two full ISO dates and an
                en dash under `whitespace-nowrap` with no `truncate`, so a
                narrower column does not clip — it SPILLS the text over the
                Entries cell, which is the one overflow mode this table has no
                defence against. Measured: the content is 174px, and 165px was
                a real regression introduced while making room for the company
                line under Batch.
              -->
              <!--
                Action is 95px because that is what its ONE button actually
                measures: 61px for "Open" plus the cell's own 32px of px-4
                padding. It was 140px while the label read "Open Notebook";
                shrinking the label without shrinking the column would have left
                47px of dead space in the widest-content table on this page.
                The slack goes to the unsized Batch column, which also renders
                the company name beneath it and is the longest text here — the
                same rule the Gotchas section records for this table.
              -->
              <colgroup>
                <col style="width: 185px" />
                <col />
                <col style="width: 180px" />
                <col style="width: 70px" />
                <col style="width: 120px" />
                <col style="width: 95px" />
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
                    Nothing {{ status === 'pending' ? 'waiting for review' : status }}
                    {{ hasFilters ? 'for this filter' : 'right now' }}.
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
                    <TooltipWrap :label="row.company || 'No company'" class="max-w-full">
                      <span class="block max-w-full truncate text-xs text-slate-400">{{ row.company || '—' }}</span>
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
                        Open
                      </RouterLink>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Below md: one stacked card per journal, so nothing scrolls sideways. -->
          <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 transition-opacity md:hidden" :class="{ 'opacity-60': isRefreshing }">
            <li v-if="rows.length === 0" class="py-6 text-center text-sm text-slate-500">
              Nothing {{ status === 'pending' ? 'waiting for review' : status }}
              {{ hasFilters ? 'for this filter' : 'right now' }}.
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
              <p class="text-sm text-slate-500">{{ row.company || '—' }}</p>
              <p class="text-sm text-slate-500">
                {{ dateOnly(row.week_start) }} &ndash; {{ dateOnly(row.week_end) }} &middot; {{ row.entries_count }} entries
              </p>
              <p class="text-xs text-slate-400">Submitted {{ formatInstant(row.submitted_at) }}</p>
              <RouterLink
                :to="notebookPath(row.student_id)"
                class="inline-block rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
              >
                Open
              </RouterLink>
            </li>
          </ul>
        </template>

        <template v-else>
          <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 transition-opacity md:block" :class="{ 'opacity-60': isRefreshing }">
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
                  <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="5">
                    {{ hasFilters ? 'No interns match this filter.' : 'No interns yet.' }}
                  </td>
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

          <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 transition-opacity md:hidden" :class="{ 'opacity-60': isRefreshing }">
            <li v-if="interns.length === 0" class="py-6 text-center text-sm text-slate-500">
              {{ hasFilters ? 'No interns match this filter.' : 'No interns yet.' }}
            </li>
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

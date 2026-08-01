<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import api from '@/lib/axios'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { Batch, BatchDetail, Department, PaginatedResponse } from '@/types/api'

/**
 * `batches.start_date` / `end_date` are DATE columns that Laravel serialises as
 * a full ISO timestamp at midnight UTC ("2026-05-29T00:00:00.000000Z"). The
 * date part is the whole value; the time component is an artefact.
 *
 * So take the leading 10 characters and never parse into a `Date`. Parsing and
 * re-formatting reads that midnight in the viewer's timezone, and every user of
 * this system is UTC+8 — "2026-05-29T00:00:00Z" is 08:00 on the 29th in Manila
 * going in, but any formatter that falls back to UTC, or any value stored an
 * hour earlier, lands on the 28th. Slicing cannot drift.
 *
 * Returns null for a missing value so the caller can render a styled em dash
 * rather than a plain string.
 */
const isoDate = (value: string | null | undefined): string | null => {
  const trimmed = value?.trim()

  return trimmed ? trimmed.slice(0, 10) : null
}

/**
 * `batch_students.enrolled_at` is aliased as the model's CREATED_AT but is NOT
 * cast, so it arrives as a bare "2026-07-29 21:37:00" with no timezone marker.
 * An unmarked string like that is already local, and `new Date()` would either
 * read it as UTC (shifting it 8 hours) or reject it outright depending on the
 * engine — so it gets sliced too. The untouched original stays reachable in a
 * tooltip, which is where the time still lives.
 */
const localDatePart = isoDate

/**
 * "21:00:00" -> "9:00 PM". Parsed by splitting the string, never through a
 * `Date`: this is a wall-clock time with no date attached, so there is no
 * instant for a Date to represent and nothing for a timezone to shift.
 */
const clockTime = (value: string | null | undefined): string | null => {
  const parts = value?.trim().split(':')
  if (!parts || parts.length < 2) return null

  const hour = Number(parts[0])
  const minute = Number(parts[1])
  if (!Number.isInteger(hour) || !Number.isInteger(minute)) return null
  if (hour < 0 || hour > 23 || minute < 0 || minute > 59) return null

  const suffix = hour < 12 ? 'AM' : 'PM'
  // 0 and 12 both display as 12 — midnight is 12 AM, noon is 12 PM.
  const display = hour % 12 === 0 ? 12 : hour % 12

  return `${display}:${String(minute).padStart(2, '0')} ${suffix}`
}

const batches = ref<Batch[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const departmentFilter = ref('')

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedBatch = ref<BatchDetail | null>(null)

const loadBatches = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<PaginatedResponse<Batch>>('/api/admin/batches', {
      params: { department_id: departmentFilter.value || undefined },
    })
    batches.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load batches.'
  } finally {
    isLoading.value = false
  }
}

const loadDepartments = async () => {
  try {
    const response = await api.get<Department[]>('/api/admin/departments')
    departments.value = response.data
  } catch {
    // Filter dropdown just stays empty; not critical to the page loading.
  }
}

watch(departmentFilter, loadBatches)

const openViewModal = async (batch: Batch) => {
  isViewOpen.value = true
  isViewLoading.value = true
  viewError.value = ''
  viewedBatch.value = null

  try {
    const response = await api.get<BatchDetail>(`/api/admin/batches/${batch.id}`)
    viewedBatch.value = response.data
  } catch {
    viewError.value = 'Unable to load batch details.'
  } finally {
    isViewLoading.value = false
  }
}

const closeViewModal = () => {
  isViewOpen.value = false
  viewedBatch.value = null
}

onMounted(() => {
  loadBatches()
  loadDepartments()
})
</script>

<template>
  <section>
    <div class="mb-5 flex flex-wrap gap-3">
      <label class="block">
        <span class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400">Department</span>
        <select v-model="departmentFilter" class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm sm:w-auto sm:min-w-56">
          <option value="">All Departments</option>
          <option v-for="department in departments" :key="department.id" :value="department.id">
            {{ department.name }}
          </option>
        </select>
      </label>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ errorMessage }}
    </p>

    <template v-else>
      <p v-if="batches.length === 0" class="rounded-xl bg-white px-6 py-8 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200/70">
        No batches found.
      </p>

      <template v-else>
        <!--
          md and up: aligned table. Widths live in the colgroup and are enforced
          by `table-fixed` — without it a browser treats col widths as hints, and
          a long program or coordinator name re-inflates the row to three lines,
          which is the problem this replaces.
        -->
        <div class="hidden overflow-x-auto rounded-xl bg-white px-6 shadow-sm ring-1 ring-slate-200/70 md:block">
          <table class="w-full table-fixed">
            <colgroup>
              <col />
              <col class="w-[120px]" />
              <col class="w-[120px]" />
              <col />
              <col class="w-[130px]" />
              <col class="w-[130px]" />
              <col class="w-[110px]" />
              <col class="w-[100px]" />
            </colgroup>
            <thead>
              <tr>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 pl-0 pr-4">Batch Name</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">Program</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">Department</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">Coordinator</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">Start Date</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">End Date</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">Status</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 pl-4 pr-0 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="batch in batches" :key="batch.id" class="transition-colors hover:bg-slate-50/70">
                <td class="truncate whitespace-nowrap py-3.5 pl-0 pr-4 text-sm font-medium text-slate-900">{{ batch.name }}</td>
                <td class="px-4 py-3.5 text-sm text-slate-500">
                  <TooltipWrap :label="batch.program?.name ?? 'No Program'" placement="top" class="max-w-full">
                    <span class="block max-w-full truncate whitespace-nowrap">{{ batch.program?.name ?? 'No Program' }}</span>
                  </TooltipWrap>
                </td>
                <td class="truncate whitespace-nowrap px-4 py-3.5 text-sm text-slate-500">
                  {{ batch.program?.department?.name ?? 'No Department' }}
                </td>
                <td class="px-4 py-3.5 text-sm text-slate-500">
                  <TooltipWrap :label="batch.coordinator?.name ?? 'No Coordinator'" placement="top" class="max-w-full">
                    <span class="block max-w-full truncate whitespace-nowrap">{{ batch.coordinator?.name ?? 'No Coordinator' }}</span>
                  </TooltipWrap>
                </td>
                <td class="whitespace-nowrap px-4 py-3.5 text-sm tabular-nums text-slate-500">
                  <span v-if="isoDate(batch.start_date)">{{ isoDate(batch.start_date) }}</span>
                  <span v-else class="text-slate-300">—</span>
                </td>
                <td class="whitespace-nowrap px-4 py-3.5 text-sm tabular-nums text-slate-500">
                  <span v-if="isoDate(batch.end_date)">{{ isoDate(batch.end_date) }}</span>
                  <span v-else class="text-slate-300">—</span>
                </td>
                <td class="whitespace-nowrap px-4 py-3.5">
                  <span
                    class="rounded-full px-3 py-1 text-xs font-bold"
                    :class="batch.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
                  >
                    {{ batch.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="whitespace-nowrap py-3.5 pl-4 pr-0 text-right">
                  <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50" @click="openViewModal(batch)">View</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Below md: one stacked block per batch, so nothing scrolls sideways. -->
        <ul class="divide-y divide-slate-100 rounded-xl bg-white px-6 shadow-sm ring-1 ring-slate-200/70 md:hidden">
          <li v-for="batch in batches" :key="batch.id" class="py-4">
            <div class="flex items-start justify-between gap-3">
              <p class="min-w-0 truncate text-sm font-medium text-slate-900">
                {{ batch.program?.name ?? 'No Program' }}
              </p>
              <span
                class="shrink-0 rounded-full px-3 py-1 text-xs font-bold"
                :class="batch.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ batch.is_active ? 'Active' : 'Inactive' }}
              </span>
            </div>
            <p class="mt-1 truncate text-xs text-slate-500">
              {{ batch.program?.department?.name ?? 'No Department' }} · {{ batch.coordinator?.name ?? 'No Coordinator' }}
            </p>
            <p class="mt-1 text-xs tabular-nums text-slate-500">
              <span v-if="isoDate(batch.start_date)">{{ isoDate(batch.start_date) }}</span>
              <span v-else class="text-slate-300">—</span>
              –
              <span v-if="isoDate(batch.end_date)">{{ isoDate(batch.end_date) }}</span>
              <span v-else class="text-slate-300">—</span>
            </p>
            <div class="mt-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50" @click="openViewModal(batch)">View</button>
            </div>
          </li>
        </ul>
      </template>
    </template>

    
    <!-- View (read-only preview) modal -->
<div v-if="isViewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <!-- Three-part flex shell: the body is the only scrolling element. -->
      <section class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">Batch Details</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeViewModal">Close</button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <p v-if="isViewLoading" class="text-sm text-slate-500">Loading...</p>
          <p v-else-if="viewError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ viewError }}</p>

          <template v-else-if="viewedBatch">
            <div class="flex flex-wrap items-center gap-3">
              <h4 class="text-xl font-semibold text-slate-900">{{ viewedBatch.name }}</h4>
              <span
                class="inline-flex shrink-0 rounded-full px-3 py-1 text-xs font-bold"
                :class="viewedBatch.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ viewedBatch.is_active ? 'Active' : 'Inactive' }}
              </span>
            </div>

            <div class="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2">
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Program</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ viewedBatch.program?.name ?? '—' }}</p>
              </div>
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Department</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ viewedBatch.program?.department?.name ?? '—' }}</p>
              </div>
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Coordinator</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ viewedBatch.coordinator?.name ?? '—' }}</p>
              </div>
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Academic Year / Semester</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ viewedBatch.academic_year ?? '—' }} / {{ viewedBatch.semester ?? '—' }}</p>
              </div>
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Start Date</p>
                <p class="mt-1 text-sm font-medium text-slate-900 tabular-nums">
                  <span v-if="isoDate(viewedBatch.start_date)">{{ isoDate(viewedBatch.start_date) }}</span>
                  <span v-else class="text-slate-300">—</span>
                </p>
              </div>
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">End Date</p>
                <p class="mt-1 text-sm font-medium text-slate-900 tabular-nums">
                  <span v-if="isoDate(viewedBatch.end_date)">{{ isoDate(viewedBatch.end_date) }}</span>
                  <span v-else class="text-slate-300">—</span>
                </p>
              </div>
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Required Hours</p>
                <p class="mt-1 text-sm font-medium text-slate-900 tabular-nums">{{ viewedBatch.required_hours }}</p>
              </div>
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Working Days / Week</p>
                <p class="mt-1 text-sm font-medium text-slate-900 tabular-nums">{{ viewedBatch.working_days_per_week }}</p>
              </div>
              <!-- Its own row, per spec. -->
              <div class="sm:col-span-2">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Daily Reminder Time</p>
                <p class="mt-1 text-sm font-medium text-slate-900 tabular-nums">
                  <span v-if="clockTime(viewedBatch.daily_reminder_time)">{{ clockTime(viewedBatch.daily_reminder_time) }}</span>
                  <span v-else class="text-slate-300">—</span>
                </p>
              </div>
            </div>

            <div class="mt-5 border-t border-slate-100 pt-5">
              <h5 class="text-xs font-medium uppercase tracking-wide text-slate-400">Enrolled Students ({{ viewedBatch.batch_students.length }})</h5>

              <p v-if="viewedBatch.batch_students.length === 0" class="mt-3 text-sm text-slate-400">
                No students enrolled in this batch yet.
              </p>

              <div v-else class="mt-1 overflow-x-auto">
                <table class="w-full table-fixed">
                  <colgroup>
                    <col />
                    <col class="w-[200px]" />
                    <col class="w-[200px]" />
                    <col class="w-[110px]" />
                    <col class="w-[120px]" />
                  </colgroup>
                  <thead>
                    <tr>
                      <th class="sticky top-0 z-10 whitespace-nowrap border-b border-slate-200 bg-white pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 pl-0 pr-4">Student</th>
                      <th class="sticky top-0 z-10 whitespace-nowrap border-b border-slate-200 bg-white pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">Company</th>
                      <th class="sticky top-0 z-10 whitespace-nowrap border-b border-slate-200 bg-white pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">Supervisor</th>
                      <th class="sticky top-0 z-10 whitespace-nowrap border-b border-slate-200 bg-white pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 px-4">Status</th>
                      <th class="sticky top-0 z-10 whitespace-nowrap border-b border-slate-200 bg-white pb-3 pt-4 text-left text-xs font-medium uppercase tracking-wide text-slate-400 pl-4 pr-0 text-right">Enrolled</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-for="record in viewedBatch.batch_students" :key="record.id" class="transition-colors hover:bg-slate-50/70">
                      <td class="py-3.5 pl-0 pr-4">
                        <p class="truncate text-sm font-medium text-slate-900">{{ record.student.name }}</p>
                        <p class="truncate text-xs text-slate-400">{{ record.student.student_id_number ?? '—' }}</p>
                      </td>
                      <td class="px-4 py-3.5 text-sm text-slate-500">
                        <TooltipWrap :label="record.company?.name ?? 'No company'" placement="top" class="max-w-full">
                          <span class="block max-w-[200px] truncate">{{ record.company?.name ?? '—' }}</span>
                        </TooltipWrap>
                      </td>
                      <td class="px-4 py-3.5 text-sm text-slate-500">
                        <TooltipWrap :label="record.supervisor?.name ?? 'No supervisor'" placement="top" class="max-w-full">
                          <span class="block max-w-[200px] truncate">{{ record.supervisor?.name ?? '—' }}</span>
                        </TooltipWrap>
                      </td>
                      <td class="whitespace-nowrap px-4 py-3.5">
                        <span
                          class="rounded-full px-2 py-0.5 text-xs font-bold capitalize"
                          :class="{
                            'bg-green-50 text-green-700': record.status === 'active',
                            'bg-blue-50 text-blue-700': record.status === 'completed',
                            'bg-red-50 text-red-700': record.status === 'dropped',
                          }"
                        >
                          {{ record.status }}
                        </span>
                      </td>
                      <td class="whitespace-nowrap py-3.5 pl-4 pr-0 text-right text-sm tabular-nums text-slate-500">
                        <TooltipWrap v-if="localDatePart(record.enrolled_at)" :label="record.enrolled_at ?? ''" placement="top" align="end">
                          <span>{{ localDatePart(record.enrolled_at) }}</span>
                        </TooltipWrap>
                        <span v-else class="text-slate-300">—</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </template>
        </div>
      </section>
    </div>
  </section>
</template>

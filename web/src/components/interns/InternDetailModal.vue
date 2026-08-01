<script setup lang="ts">
import { computed, ref } from 'vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { InternDetail } from '@/types/api'

const props = defineProps<{ detail: InternDetail | null; isLoading: boolean; errorMessage?: string }>()
defineEmits<{ close: [] }>()

const initials = computed(() =>
  (props.detail?.name ?? '')
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/**
 * `date_of_birth` is a date-only value, so it is read straight off the string
 * rather than parsed. `new Date("2002-05-12T00:00:00.000000Z")` re-formatted in
 * a UTC+8 locale can land on the 11th; splitting the Y-M-D cannot drift.
 */
const formatDate = (value: string | null | undefined): string => {
  const parts = value?.trim().slice(0, 10).split('-')
  if (!parts || parts.length !== 3) return '—'

  const [year, month, day] = parts.map(Number)
  if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) return '—'
  if (month < 1 || month > 12) return '—'

  return `${MONTHS[month - 1]} ${day}, ${year}`
}

const copiedContact = ref(false)
let copyResetId: ReturnType<typeof setTimeout> | null = null

const copyContact = async () => {
  const number = props.detail?.profile?.contact_number
  if (!number) return

  try {
    await navigator.clipboard.writeText(number)
    copiedContact.value = true
    if (copyResetId !== null) clearTimeout(copyResetId)
    copyResetId = setTimeout(() => {
      copiedContact.value = false
    }, 2000)
  } catch {
    // Clipboard is permission-gated and unavailable on insecure origins; the
    // tel: link still works, so a failure here is not worth surfacing.
  }
}
</script>

<template>
  <!-- Three-part flex shell: the body is the only scrolling element. -->
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
    <section class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
      <div class="shrink-0 border-b border-slate-200 px-6 py-4">
        <div class="flex items-start justify-between gap-4">
          <div class="flex min-w-0 items-center gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-lg font-bold text-white">
              <img v-if="detail?.avatar_url" :src="detail.avatar_url" alt="Profile photo" class="h-full w-full object-cover" />
              <span v-else>{{ initials || '—' }}</span>
            </div>
            <div class="min-w-0">
              <h3 class="truncate text-xl font-semibold text-slate-900">{{ detail?.name ?? 'Intern Details' }}</h3>
              <p class="mt-0.5 truncate text-sm text-slate-500">
                {{ detail?.student_id_number ?? 'No student ID on file' }}
              </p>
            </div>
          </div>
          <button type="button" class="shrink-0 text-sm font-medium text-slate-500 hover:text-slate-900" @click="$emit('close')">
            Close
          </button>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto px-6 py-5">
        <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
        <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>

        <template v-else-if="detail">
          <div class="grid gap-x-8 gap-y-5 sm:grid-cols-2">
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Email</p>
              <p class="mt-1 text-sm font-medium text-slate-900">
                <TooltipWrap v-if="detail.email" :label="detail.email" placement="top" class="max-w-full">
                  <a :href="`mailto:${detail.email}`" class="block max-w-full truncate text-blue-600 transition hover:text-blue-700">
                    {{ detail.email }}
                  </a>
                </TooltipWrap>
                <span v-else>—</span>
              </p>
            </div>
            <div v-if="detail.username" class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Username</p>
              <p class="mt-1 truncate text-sm font-medium text-slate-900">{{ detail.username }}</p>
            </div>
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Program</p>
              <p class="mt-1 text-sm font-medium text-slate-900">{{ detail.program?.name ?? '—' }}</p>
            </div>
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Year Level</p>
              <p class="mt-1 text-sm font-medium text-slate-900">{{ detail.profile?.year_level ?? '—' }}</p>
            </div>
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Contact Number</p>
              <div v-if="detail.profile?.contact_number" class="mt-1 flex items-center gap-2">
                <a
                  :href="`tel:${detail.profile.contact_number}`"
                  class="truncate text-sm font-medium text-blue-600 transition hover:text-blue-700"
                >
                  {{ detail.profile.contact_number }}
                </a>
                <TooltipWrap :label="copiedContact ? 'Copied' : 'Copy number'" placement="top">
                  <button
                    type="button"
                    :aria-label="copiedContact ? 'Copied' : 'Copy number'"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500"
                    @click="copyContact"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                      <path v-if="copiedContact" d="m5 12.5 4.5 4.5L19 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                      <g v-else>
                        <rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.6" />
                        <path d="M15 6.5V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                      </g>
                    </svg>
                  </button>
                </TooltipWrap>
              </div>
              <p v-else class="mt-1 text-sm font-medium text-slate-900">—</p>
            </div>
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Sex</p>
              <p class="mt-1 text-sm font-medium text-slate-900">{{ detail.profile?.sex ?? '—' }}</p>
            </div>
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Date of Birth</p>
              <p class="mt-1 text-sm font-medium tabular-nums text-slate-900">{{ formatDate(detail.profile?.date_of_birth) }}</p>
            </div>
            <div class="min-w-0 sm:col-span-2">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Home Address</p>
              <p class="mt-1 text-sm font-medium text-slate-900">{{ detail.profile?.home_address ?? '—' }}</p>
            </div>
          </div>

          <div class="mt-5 border-t border-slate-100 pt-5">
            <div class="rounded-lg p-4 ring-1 ring-slate-200">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Current Placement</p>
              <div v-if="detail.enrollment" class="mt-3 grid gap-x-8 gap-y-5 sm:grid-cols-2">
                <div class="min-w-0">
                  <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Batch</p>
                  <p class="mt-1 text-sm font-medium text-slate-900">{{ detail.enrollment.batch?.name ?? '—' }}</p>
                </div>
                <div class="min-w-0">
                  <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Company</p>
                  <p class="mt-1 text-sm font-medium text-slate-900">{{ detail.enrollment.company?.name ?? '—' }}</p>
                </div>
                <div v-if="detail.enrollment.supervisor" class="min-w-0">
                  <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Supervisor</p>
                  <p class="mt-1 text-sm font-medium text-slate-900">{{ detail.enrollment.supervisor.name }}</p>
                </div>
              </div>
              <p v-else class="mt-2 text-sm text-slate-400">Not currently enrolled.</p>
            </div>
          </div>
        </template>
      </div>

      <div class="flex shrink-0 justify-end border-t border-slate-200 bg-white px-6 py-4">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          @click="$emit('close')"
        >
          Close
        </button>
      </div>
    </section>
  </div>
</template>

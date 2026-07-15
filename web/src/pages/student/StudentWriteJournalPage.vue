<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import api from '@/lib/axios'
import JournalPaperView from '@/components/journal/JournalPaperView.vue'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import { confirmAction } from '@/lib/toast'
import { isNotEnrolledError } from '@/lib/enrollment'
import type { JournalEntryDetail, JournalTemplateSection } from '@/types/api'

const route = useRoute()
const router = useRouter()

const entryDate = computed(() => (typeof route.query.date === 'string' ? route.query.date : new Date().toISOString().slice(0, 10)))

const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const statusMessage = ref('')
const notEnrolled = ref(false)
const status = ref<JournalEntryDetail['status']>('draft')
const editable = ref(true)
const lockedReason = ref<JournalEntryDetail['locked_reason']>(null)
const sections = ref<JournalTemplateSection[]>([])
const charLimit = ref(1500)
const studentName = ref('')
const programName = ref<string | null>(null)
const entryOrdinalLabel = ref('')
const content = reactive<Record<string, string>>({})
const enabledSections = reactive<Record<string, boolean>>({})
const isViewMode = ref(false)

const sippCharLimit = 300
const sippEnabled = ref(false)

const nonSippSections = computed(() => sections.value.filter((section) => !section.sipp))
const sippSections = computed(() => sections.value.filter((section) => section.sipp))

const charCount = computed(() =>
  Object.values(content).reduce((total, value) => total + value.length, 0),
)

const isOverLimit = computed(() => charCount.value > charLimit.value)

const sippLength = (key: string) => (content[key] ?? '').length

const isSippOverLimit = computed(() =>
  sippSections.value.some((section) => sippLength(section.key) > sippCharLimit),
)

const toggleSection = (section: JournalTemplateSection, checked: boolean) => {
  enabledSections[section.key] = checked

  if (checked) {
    if (!(section.key in content)) {
      content[section.key] = ''
    }
  } else {
    delete content[section.key]
  }
}

const toggleSipp = (checked: boolean) => {
  sippEnabled.value = checked

  sippSections.value.forEach((section) => {
    if (checked) {
      if (!(section.key in content)) {
        content[section.key] = ''
      }
    } else {
      delete content[section.key]
    }
  })
}

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  statusMessage.value = ''
  notEnrolled.value = false
  isViewMode.value = route.query.view === '1'

  try {
    const { data } = await api.get<JournalEntryDetail>(`/api/student/journal-entries/${entryDate.value}`)
    sections.value = data.sections
    charLimit.value = data.char_limit
    status.value = data.status
    editable.value = data.editable
    lockedReason.value = data.locked_reason
    studentName.value = data.student_name
    programName.value = data.program
    entryOrdinalLabel.value = data.entry_ordinal_label

    Object.keys(content).forEach((key) => delete content[key])
    Object.keys(enabledSections).forEach((key) => delete enabledSections[key])

    data.sections.forEach((section) => {
      const existingValue = data.content[section.key] ?? ''

      if (section.required) {
        content[section.key] = existingValue
      } else if (!section.sipp) {
        enabledSections[section.key] = existingValue.trim() !== ''
        if (enabledSections[section.key]) {
          content[section.key] = existingValue
        }
      }
    })

    const sippFields = data.sections.filter((section) => section.sipp)
    sippEnabled.value = sippFields.some((section) => (data.content[section.key] ?? '').trim() !== '')
    sippFields.forEach((section) => {
      if (sippEnabled.value) {
        content[section.key] = data.content[section.key] ?? ''
      }
    })

  } catch (error) {
    if (isNotEnrolledError(error)) {
      notEnrolled.value = true
    } else {
      errorMessage.value = 'Unable to load this journal entry.'
    }
  } finally {
    isLoading.value = false
  }
}

const save = async (nextStatus: 'draft' | 'submitted') => {
  // Submitting still locks the entry once its week is bundled, so it keeps
  // the confirm-first treatment even though it's no longer immediately final.
  if (
    nextStatus === 'submitted' &&
    !confirmAction('Submit this journal entry? You can still edit it until this week is compiled into your Weekly Log.')
  ) {
    return
  }

  isSaving.value = true
  errorMessage.value = ''
  statusMessage.value = ''
  notEnrolled.value = false

  try {
    const { data } = await api.post<JournalEntryDetail>('/api/student/journal-entries', {
      entry_date: entryDate.value,
      status: nextStatus,
      content,
    })
    status.value = data.status
    statusMessage.value = nextStatus === 'submitted' ? 'Entry submitted.' : 'Draft saved.'

    if (nextStatus === 'submitted') {
      isViewMode.value = true
    }
  } catch (error) {
    if (isNotEnrolledError(error)) {
      notEnrolled.value = true
    } else {
      const data = axios.isAxiosError(error) ? error.response?.data : null
      errorMessage.value = data?.message ?? 'Unable to save this entry.'
    }
  } finally {
    isSaving.value = false
  }
}

const backToEditor = () => {
  isViewMode.value = false
}

const openDocumentView = () => {
  isViewMode.value = true
}

// The document view is directly editable for as long as the server says
// so — within the OJT date range AND before this week gets bundled into
// the student's Weekly Log. Submission status no longer locks it.
const paperEditable = computed(() => editable.value)

const downloadPdf = () => {
  window.open(`/api/student/journal-entries/${entryDate.value}/pdf`, '_blank')
}

const goToDate = (date: string) => {
  router.push({ path: '/student/write-journal', query: { date } })
}

const onDateChange = (event: Event) => {
  goToDate((event.target as HTMLInputElement).value)
}

watch(entryDate, load)
onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <NotEnrolledNotice v-else-if="notEnrolled" />

    <template v-else-if="isViewMode">
      <div
        v-if="lockedReason === 'bundled'"
        class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700"
      >
        This week has already been compiled into your Weekly Log — daily entries for this week can no longer be edited.
      </div>
      <div
        v-else-if="status === 'submitted'"
        class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800"
      >
        Submitted — you can still edit this entry until your weekly log for this week is compiled.
      </div>

      <div class="flex flex-wrap items-center justify-end gap-3">
        <span
          v-if="paperEditable"
          class="mr-auto font-mono text-xs"
          :class="isOverLimit ? 'font-semibold text-red-600' : 'text-slate-400'"
        >
          {{ charCount }} / {{ charLimit }}
        </span>
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
          @click="downloadPdf"
        >
          Download PDF
        </button>
        <button
          v-if="editable"
          type="button"
          class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
          @click="backToEditor"
        >
          Edit in Form
        </button>
        <button
          v-if="paperEditable"
          type="button"
          class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50"
          :disabled="isSaving || isOverLimit || isSippOverLimit"
          @click="save('draft')"
        >
          Save Draft
        </button>
        <button
          v-if="paperEditable"
          type="button"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="isSaving || isOverLimit || isSippOverLimit"
          @click="save('submitted')"
        >
          Submit Entry
        </button>
      </div>

      <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
      <p v-if="statusMessage" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>

      <JournalPaperView
        :entry-date="entryDate"
        :sections="sections"
        :content="content"
        :student-name="studentName"
        :program-name="programName"
        :entry-ordinal-label="entryOrdinalLabel"
        :editable="paperEditable"
      />
    </template>

    <template v-else>
      <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Writing journal for <strong>{{ entryDate }}</strong>.
        <span v-if="lockedReason === 'bundled'"> This week's entries have already been compiled into your Weekly Log and can no longer be edited.</span>
        <span v-else-if="!editable"> This date can no longer be edited (future date or outside your OJT range).</span>
      </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div class="space-y-5">
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Entry Details</h2>
          <label class="mt-4 block text-sm font-medium text-slate-700">
            Date
            <input
              type="date"
              :value="entryDate"
              class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
              @change="onDateChange"
            />
          </label>
        </section>

        <section v-for="section in nonSippSections" :key="section.key" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="text-sm font-bold text-slate-900">
                {{ section.label }}
                <span v-if="section.required" class="text-red-500">*</span>
              </h2>
              <p class="mt-1 text-xs text-slate-400">{{ section.prompt }}</p>
            </div>
            <label v-if="!section.required" class="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-600">
              <input
                type="checkbox"
                :checked="!!enabledSections[section.key]"
                :disabled="!editable"
                @change="toggleSection(section, ($event.target as HTMLInputElement).checked)"
              />
              Include
            </label>
          </div>

          <textarea
            v-if="section.required || enabledSections[section.key]"
            v-model="content[section.key]"
            :disabled="!editable"
            class="mt-3 min-h-40 w-full rounded-md border border-slate-300 px-4 py-3 text-sm leading-6 text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
          />
        </section>

        <section v-if="sippSections.length > 0" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="text-sm font-bold text-slate-900">
                SIPP Report (Annex C)
              </h2>
              <p class="mt-1 text-xs text-slate-400">Issues, solutions, and recommendations for your SIPP compliance report.</p>
            </div>
            <label class="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-600">
              <input
                type="checkbox"
                :checked="sippEnabled"
                :disabled="!editable"
                @change="toggleSipp(($event.target as HTMLInputElement).checked)"
              />
              SIPP Report
            </label>
          </div>

          <div v-if="sippEnabled" class="mt-4 space-y-4">
            <div v-for="section in sippSections" :key="section.key">
              <div class="flex items-center justify-between gap-3">
                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ section.label }}</h3>
                <span
                  class="font-mono text-xs"
                  :class="sippLength(section.key) >= sippCharLimit ? 'font-semibold text-red-600' : 'text-slate-400'"
                >
                  {{ sippLength(section.key) }} / {{ sippCharLimit }}
                </span>
              </div>
              <p class="mt-1 text-xs text-slate-400">{{ section.prompt }}</p>
              <textarea
                v-model="content[section.key]"
                :disabled="!editable"
                :maxlength="sippCharLimit"
                class="mt-2 min-h-24 w-full rounded-md border border-slate-300 px-4 py-3 text-sm leading-6 text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
              />
            </div>
          </div>
        </section>

        <p v-if="sections.length === 0" class="text-sm text-slate-500">
          No journal template sections are configured for your batch yet.
        </p>
      </div>

      <aside class="space-y-5">
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Entry Summary</h2>
          <div class="mt-4 divide-y divide-slate-100 text-sm">
            <div class="flex justify-between py-2">
              <span class="text-slate-500">Character Count</span>
              <span class="font-mono font-semibold" :class="isOverLimit ? 'text-red-600' : ''">{{ charCount }} / {{ charLimit }}</span>
            </div>
            <div class="flex justify-between py-2"><span class="text-slate-500">Status</span><span class="font-semibold capitalize">{{ status }}</span></div>
          </div>
          <p v-if="isOverLimit" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
            This entry exceeds the {{ charLimit }}-character limit. Trim it down before saving.
          </p>
        </section>

        <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
        <p v-if="statusMessage" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>

        <div class="flex justify-end gap-3">
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
            @click="openDocumentView"
          >
            Document View
          </button>
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50"
            :disabled="isSaving || !editable || isOverLimit || isSippOverLimit"
            @click="save('draft')"
          >
            Save Draft
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="isSaving || !editable || isOverLimit || isSippOverLimit"
            @click="save('submitted')"
          >
            Submit Entry
          </button>
        </div>
      </aside>
    </div>
    </template>
  </section>
</template>

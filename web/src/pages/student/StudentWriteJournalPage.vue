<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import api from '@/lib/axios'
import JournalPaperView from '@/components/journal/JournalPaperView.vue'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import { confirmAction } from '@/lib/toast'
import { isNotEnrolledError } from '@/lib/enrollment'
import { useFormDraft } from '@/lib/formDraft'
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
const dayLabel = ref('')
const content = reactive<Record<string, string>>({})
const enabledSections = reactive<Record<string, boolean>>({})

// The Edit ⇄ Preview segmented switch. Preview renders the read-only paper —
// the mandatory review step before an entry can finally be submitted.
const isViewMode = ref(false)

const sippCharLimit = 300
const sippEnabled = ref(false)

/**
 * Keep an unsaved entry alive across a refresh.
 *
 * The stored draft carries its own `date` and is only re-applied when it matches
 * the entry currently open — otherwise switching days (the date input pushes a
 * new ?date= query, which re-runs load() without remounting) could spill one
 * day's writing into another. Only the most recently edited day is kept, which
 * is enough: the date lives in the URL, so a refresh returns to the same day.
 *
 * `autoRestore: false` because load() rebuilds `content`/`enabledSections` from
 * the API and would overwrite an eager restore.
 */
const journalDraft = useFormDraft(
  'student:journal-entry',
  () => ({
    date: entryDate.value,
    content: { ...content },
    enabled: { ...enabledSections },
    sipp: sippEnabled.value,
  }),
  (draft) => {
    if (draft.date !== entryDate.value) return
    if (draft.content) Object.assign(content, draft.content)
    if (draft.enabled) Object.assign(enabledSections, draft.enabled)
    if (typeof draft.sipp === 'boolean') sippEnabled.value = draft.sipp
  },
  { autoRestore: false },
)

const nonSippSections = computed(() => sections.value.filter((section) => !section.sipp))
const sippSections = computed(() => sections.value.filter((section) => section.sipp))

// Writing blocks currently on the page: every required section, plus any
// optional section the student has added via the "Add to this entry" chips.
const visibleNonSipp = computed(() =>
  nonSippSections.value.filter((section) => section.required || enabledSections[section.key]),
)

// Optional sections not yet added — offered as add-chips OUTSIDE the writing
// surface, so no checkbox ever sits where the student is typing.
const addableSections = computed(() =>
  nonSippSections.value.filter((section) => !section.required && !enabledSections[section.key]),
)
const canAddSipp = computed(() => sippSections.value.length > 0 && !sippEnabled.value)
const hasAddable = computed(() => addableSections.value.length > 0 || canAddSipp.value)

const charCount = computed(() =>
  Object.values(content).reduce((total, value) => total + value.length, 0),
)

const isOverLimit = computed(() => charCount.value > charLimit.value)

const sippLength = (key: string) => (content[key] ?? '').length

const isSippOverLimit = computed(() =>
  sippSections.value.some((section) => sippLength(section.key) > sippCharLimit),
)

const cannotSave = computed(() => isSaving.value || !editable.value || isOverLimit.value || isSippOverLimit.value)

const addSection = (section: JournalTemplateSection) => {
  enabledSections[section.key] = true
  if (!(section.key in content)) content[section.key] = ''
}

const removeSection = (section: JournalTemplateSection) => {
  enabledSections[section.key] = false
  delete content[section.key]
}

const addSipp = () => {
  sippEnabled.value = true
  sippSections.value.forEach((section) => {
    if (!(section.key in content)) content[section.key] = ''
  })
}

const removeSipp = () => {
  sippEnabled.value = false
  sippSections.value.forEach((section) => delete content[section.key])
}

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  statusMessage.value = ''
  notEnrolled.value = false

  try {
    const { data } = await api.get<JournalEntryDetail>(`/api/student/journal-entries/${entryDate.value}`)
    sections.value = data.sections
    charLimit.value = data.char_limit
    status.value = data.status
    editable.value = data.editable
    lockedReason.value = data.locked_reason
    studentName.value = data.student_name
    programName.value = data.program
    dayLabel.value = data.day_label

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

    // A locked entry (bundled / out of range) or an explicit ?view=1 opens
    // straight to the read-only paper; an editable entry lands in the editor.
    isViewMode.value = route.query.view === '1' || !data.editable

    // Layer any unsaved work back on top, now that the server copy is in place.
    // Never into a locked entry — it can't be saved, so restoring would only
    // show text the student is unable to keep.
    if (data.editable) journalDraft.restore()
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
  if (nextStatus === 'submitted') {
    const confirmed = await confirmAction({
      title: 'Submit this journal entry?',
      message:
        'Submit this journal entry? You can still edit it until this week is compiled into your Weekly Log.',
      confirmLabel: 'Submit Entry',
    })
    if (!confirmed) return
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
    // Persisted server-side now; drop the local copy.
    journalDraft.clear()

    // A submitted entry stays on the paper (the review surface it was sent
    // from); a saved draft returns the student to the editor to keep writing.
    isViewMode.value = nextStatus === 'submitted'
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

const setMode = (mode: 'edit' | 'preview') => {
  statusMessage.value = ''
  isViewMode.value = mode === 'preview'
}

const downloadPdf = () => {
  window.open(`/api/student/journal-entries/${entryDate.value}/pdf`, '_blank')
}

const onDateChange = (event: Event) => {
  const date = (event.target as HTMLInputElement).value
  if (date) router.push({ path: '/student/write-journal', query: { date } })
}

watch(entryDate, load)
onMounted(load)
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-4 pb-24 md:pb-4">
    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <NotEnrolledNotice v-else-if="notEnrolled" />

    <template v-else>
      <!-- Header: compact date + Edit/Preview segmented switch -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <span v-if="dayLabel" class="text-sm font-bold text-slate-900">{{ dayLabel }}</span>
          <input
            type="date"
            :value="entryDate"
            aria-label="Entry date"
            class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-sm text-slate-600 focus:border-blue-500"
            @change="onDateChange"
          />
        </div>

        <div class="inline-flex rounded-lg border border-slate-200 bg-slate-100 p-0.5 text-sm font-semibold">
          <button
            type="button"
            class="rounded-md px-4 py-1.5 transition"
            :class="!isViewMode ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
            @click="setMode('edit')"
          >
            Edit
          </button>
          <button
            type="button"
            class="rounded-md px-4 py-1.5 transition"
            :class="isViewMode ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
            @click="setMode('preview')"
          >
            Preview
          </button>
        </div>
      </div>

      <!-- Lock / editability notices (kept thin) -->
      <div
        v-if="lockedReason === 'bundled'"
        class="rounded-md border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-700"
      >
        This week has already been compiled into your Weekly Log — daily entries for this week can no longer be edited.
      </div>
      <div
        v-else-if="!editable"
        class="rounded-md border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-700"
      >
        This date is outside the range you can write in — it is either in the future or outside your OJT period. Pick a
        date within your OJT range using the date picker above.
      </div>
      <div
        v-else-if="status === 'submitted'"
        class="rounded-md border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm text-blue-800"
      >
        Submitted — you can still edit this entry until your weekly log for this week is compiled.
      </div>

      <!-- EDIT MODE — clean writing surface, no checkboxes inline -->
      <template v-if="!isViewMode">
        <p v-if="sections.length === 0" class="rounded-lg bg-white p-5 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
          No journal template sections are configured for your batch yet. Ask your coordinator to assign a journal
          template so you can start writing.
        </p>

        <article
          v-for="section in visibleNonSipp"
          :key="section.key"
          class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="text-sm font-bold text-slate-900">
                {{ section.label }}
                <span v-if="section.required" class="text-red-500">*</span>
              </h2>
              <p v-if="section.prompt" class="mt-1 text-xs text-slate-400">{{ section.prompt }}</p>
            </div>
            <button
              v-if="!section.required && editable"
              type="button"
              class="shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-slate-400 transition hover:bg-red-50 hover:text-red-600"
              @click="removeSection(section)"
            >
              Remove
            </button>
          </div>

          <textarea
            v-model="content[section.key]"
            :disabled="!editable"
            rows="6"
            class="mt-3 w-full rounded-md border border-slate-200 px-4 py-3 text-sm leading-6 text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
          />
        </article>

        <!-- SIPP block — its own card once added, with per-field counters -->
        <article v-if="sippEnabled" class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="text-sm font-bold text-slate-900">SIPP Report (Annex C)</h2>
              <p class="mt-1 text-xs text-slate-400">Issues, solutions, and recommendations for your SIPP compliance report.</p>
            </div>
            <button
              v-if="editable"
              type="button"
              class="shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-slate-400 transition hover:bg-red-50 hover:text-red-600"
              @click="removeSipp"
            >
              Remove
            </button>
          </div>

          <div class="mt-4 space-y-4">
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
              <p v-if="section.prompt" class="mt-1 text-xs text-slate-400">{{ section.prompt }}</p>
              <textarea
                v-model="content[section.key]"
                :disabled="!editable"
                :maxlength="sippCharLimit"
                rows="3"
                class="mt-2 w-full rounded-md border border-slate-200 px-4 py-3 text-sm leading-6 text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
              />
            </div>
          </div>
        </article>

        <!-- Add-chips — the section chooser, deliberately OUTSIDE the writing cards -->
        <div v-if="editable && hasAddable" class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Add to this entry</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button
              v-for="section in addableSections"
              :key="section.key"
              type="button"
              class="inline-flex items-center gap-1 rounded-full border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:border-blue-400 hover:text-blue-700"
              @click="addSection(section)"
            >
              + {{ section.label }}
            </button>
            <button
              v-if="canAddSipp"
              type="button"
              class="inline-flex items-center gap-1 rounded-full border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:border-blue-400 hover:text-blue-700"
              @click="addSipp"
            >
              + SIPP Report (Annex C)
            </button>
          </div>
        </div>
      </template>

      <!-- PREVIEW MODE — read-only paper, the review gate before submitting -->
      <template v-else>
        <div class="rounded-lg bg-slate-100 p-4 sm:p-6">
          <JournalPaperView
            :entry-date="entryDate"
            :sections="sections"
            :content="content"
            :student-name="studentName"
            :program-name="programName"
            :day-label="dayLabel"
            :editable="false"
          />
        </div>
      </template>

      <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
      <p v-if="statusMessage" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>
      <p v-if="isOverLimit" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
        This entry exceeds the {{ charLimit }}-character limit. Trim it down before saving.
      </p>

      <!-- Action bar — sticky at the bottom on mobile, inline on desktop -->
      <div
        class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-3 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur md:static md:mx-0 md:border-0 md:bg-transparent md:px-0 md:py-0"
      >
        <span
          v-if="editable"
          class="mr-auto font-mono text-xs"
          :class="isOverLimit ? 'font-semibold text-red-600' : 'text-slate-400'"
        >
          {{ charCount }} / {{ charLimit }}
        </span>

        <button
          v-if="isViewMode"
          type="button"
          class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
          @click="downloadPdf"
        >
          Download PDF
        </button>

        <template v-if="editable">
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="cannotSave"
            @click="save('draft')"
          >
            Save Draft
          </button>

          <button
            v-if="!isViewMode"
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isSaving || isOverLimit || isSippOverLimit"
            @click="setMode('preview')"
          >
            Review &amp; Submit
          </button>
          <button
            v-else
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="cannotSave"
            @click="save('submitted')"
          >
            Submit Entry
          </button>
        </template>
      </div>
    </template>
  </section>
</template>

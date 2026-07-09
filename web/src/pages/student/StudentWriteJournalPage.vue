<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import api from '@/lib/axios'
import JournalPaperView from '@/components/journal/JournalPaperView.vue'
import type { JournalEntryDetail, JournalTemplateSection } from '@/types/api'

const route = useRoute()
const router = useRouter()

const entryDate = computed(() => (typeof route.query.date === 'string' ? route.query.date : new Date().toISOString().slice(0, 10)))

const isLoading = ref(true)
const isSaving = ref(false)
const activeSaveAction = ref<'draft' | 'submitted' | null>(null)
const errorMessage = ref('')
const statusMessage = ref('')
const status = ref<JournalEntryDetail['status']>('draft')
const editable = ref(true)
const sections = ref<JournalTemplateSection[]>([])
const wordLimit = ref(500)
const content = reactive<Record<string, string>>({})
const enabledSections = reactive<Record<string, boolean>>({})
const isViewMode = ref(false)
const showSubmitConfirm = ref(false)
const issueRecommendationKeys = new Set(['issues_concerns', 'solutions', 'recommendations'])

const wordCount = computed(() =>
  Object.values(content).reduce((total, value) => total + (value.trim() ? value.trim().split(/\s+/).length : 0), 0),
)
const characterCount = computed(() => Object.values(content).reduce((total, value) => total + value.length, 0))

const isOverLimit = computed(() => wordCount.value > wordLimit.value)

const displaySectionLabel = (section: JournalTemplateSection) =>
  section.key === 'issues_concerns' ? 'Problem / Concern' : section.label

const displaySections = computed(() =>
  sections.value.map((section) => ({
    ...section,
    label: displaySectionLabel(section),
  })),
)

const requiredSections = computed(() => displaySections.value.filter((section) => section.required))
const optionalSections = computed(() => displaySections.value.filter((section) => !section.required))
const selectedOptionalSections = computed(() => optionalSections.value.filter((section) => enabledSections[section.key]))
const selectedOptionalCount = computed(() => selectedOptionalSections.value.length)
const optionalSectionGroups = computed(() =>
  [
    {
      title: 'Learning Reflection',
      sections: optionalSections.value.filter((section) => !issueRecommendationKeys.has(section.key)),
    },
    {
      title: 'Issues and Recommendations',
      sections: optionalSections.value.filter((section) => issueRecommendationKeys.has(section.key)),
    },
  ].filter((group) => group.sections.length > 0),
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

const load = async (showLoading = false) => {
  if (showLoading) {
    isLoading.value = true
  }

  errorMessage.value = ''
  statusMessage.value = ''
  isViewMode.value = route.query.view === '1'
  showSubmitConfirm.value = false

  try {
    const { data } = await api.get<JournalEntryDetail>(`/api/student/journal-entries/${entryDate.value}`)
    sections.value = data.sections
    wordLimit.value = data.word_limit
    status.value = data.status
    editable.value = data.editable

    Object.keys(content).forEach((key) => delete content[key])
    Object.keys(enabledSections).forEach((key) => delete enabledSections[key])

    data.sections.forEach((section) => {
      const existingValue = data.content[section.key] ?? ''

      if (section.required) {
        content[section.key] = section.key === 'task_performed' ? '' : existingValue
      } else {
        enabledSections[section.key] = false
      }
    })
  } catch {
    errorMessage.value = 'Unable to load this journal entry.'
  } finally {
    if (showLoading) {
      isLoading.value = false
    }
  }
}

const save = async (nextStatus: 'draft' | 'submitted') => {
  isSaving.value = true
  activeSaveAction.value = nextStatus
  errorMessage.value = ''
  statusMessage.value = ''

  try {
    const { data } = await api.post<JournalEntryDetail>('/api/student/journal-entries', {
      entry_date: entryDate.value,
      status: nextStatus,
      content,
    })
    status.value = data.status
    statusMessage.value = nextStatus === 'submitted' ? 'Entry submitted.' : 'Draft saved.'
    return true
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    errorMessage.value = data?.message ?? 'Unable to save this entry.'
    return false
  } finally {
    isSaving.value = false
  }
}

const previewEntry = () => {
  errorMessage.value = ''
  statusMessage.value = ''
  showSubmitConfirm.value = false
  isViewMode.value = true
}

const askSubmitConfirmation = () => {
  showSubmitConfirm.value = true
}

const cancelSubmitConfirmation = () => {
  showSubmitConfirm.value = false
}

const clearForm = () => {
  displaySections.value.forEach((section) => {
    if (section.required) {
      content[section.key] = ''
    } else {
      enabledSections[section.key] = false
      delete content[section.key]
    }
  })
}

const confirmSubmit = async () => {
  const submitted = await save('submitted')

  if (submitted) {
    clearForm()
    showSubmitConfirm.value = false
    isViewMode.value = false
  }
}

const backToEditor = () => {
  showSubmitConfirm.value = false
  isViewMode.value = false
}

const goToDate = (date: string) => {
  router.push({ path: '/student/write-journal', query: { date } })
}

const onDateChange = (event: Event) => {
  goToDate((event.target as HTMLInputElement).value)
}

watch(entryDate, () => load())
onMounted(() => load(true))
</script>

<template>
  <section class="space-y-5">
    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>

    <template v-else-if="isViewMode">
      <div class="flex flex-col items-end gap-3">
        <div class="flex justify-end gap-3">
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
            @click="backToEditor"
          >
            Edit Entry
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="isSaving || isOverLimit"
            @click="askSubmitConfirmation"
          >
            Submit
          </button>
        </div>
        <div v-if="showSubmitConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4">
          <div class="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-5 text-center text-sm shadow-xl">
            <p class="font-semibold text-slate-900">Are you sure you want to submit?</p>
            <div class="mt-4 flex justify-center gap-2">
              <button
                type="button"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                :disabled="isSaving"
                @click="cancelSubmitConfirmation"
              >
                No
              </button>
              <button
                type="button"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                :disabled="isSaving"
                @click="confirmSubmit"
              >
                Yes
              </button>
            </div>
          </div>
        </div>
      </div>
      <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
      <p v-if="isOverLimit" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
        This entry exceeds the {{ wordLimit }}-word limit. Trim it down before submitting.
      </p>
      <JournalPaperView :entry-date="entryDate" :sections="displaySections" :content="content" />
    </template>

    <template v-else>
      <p v-if="!editable" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        This date can no longer be edited (future date or outside your OJT range).
      </p>

      <div class="space-y-5">
        <div class="flex flex-col gap-3 sm:max-w-xs">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Entry Details</p>
          <label class="block text-sm font-semibold text-slate-700">
            Date
            <input
              type="date"
              :value="entryDate"
              class="mt-2 block w-full rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
              @change="onDateChange"
            />
          </label>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
          <div class="space-y-4">
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
              <div class="border-b border-slate-200 px-6 py-5 text-center">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Daily Journal Entry</p>
                <h2 class="mt-1 text-xl font-bold text-slate-950">Daily Accomplishments</h2>
              </div>

              <div class="space-y-6 p-6">
                <div v-for="section in requiredSections" :key="section.key" class="space-y-3">
                  <div>
                    <h3 class="text-sm font-bold text-slate-900">
                      {{ section.label }}
                      <span class="text-red-500">*</span>
                    </h3>
                    <p v-if="section.prompt" class="mt-1 text-xs text-slate-500">{{ section.prompt }}</p>
                  </div>
                  <textarea
                    v-model="content[section.key]"
                    :disabled="!editable"
                    class="min-h-[360px] w-full resize-y rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm leading-7 text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                  />
                </div>

                <p v-if="requiredSections.length === 0" class="text-sm text-slate-500">
                  No required daily accomplishment section is configured for your batch yet.
                </p>

                <div v-if="selectedOptionalCount" class="space-y-5 border-t border-slate-200 pt-6">
                  <div
                    v-for="section in selectedOptionalSections"
                    :key="section.key"
                    class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                  >
                    <div class="flex items-center justify-between gap-3">
                      <div>
                        <h3 class="text-sm font-bold text-slate-900">{{ section.label }}</h3>
                        <p v-if="section.prompt" class="mt-1 text-xs text-slate-500">{{ section.prompt }}</p>
                      </div>
                      <span
                        v-if="section.sipp"
                        class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700"
                      >
                        SIPP
                      </span>
                    </div>
                    <textarea
                      v-model="content[section.key]"
                      :disabled="!editable"
                      class="mt-4 min-h-28 w-full rounded-md border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    />
                  </div>
                </div>
              </div>
            </section>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <p class="text-sm font-medium" :class="isOverLimit ? 'text-red-600' : 'text-slate-500'">
                {{ characterCount }} characters
              </p>

              <div class="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  class="rounded-md border px-5 py-2.5 text-sm font-semibold shadow-sm transition disabled:opacity-75"
                  :class="
                    activeSaveAction === 'draft'
                      ? 'border-blue-600 bg-blue-600 text-white'
                      : 'border-slate-300 bg-white text-slate-700 hover:border-blue-600 hover:bg-blue-600 hover:text-white'
                  "
                  :disabled="isSaving || !editable || isOverLimit"
                  @click="save('draft')"
                >
                  {{ isSaving && activeSaveAction === 'draft' ? 'Saving Draft...' : 'Save Draft' }}
                </button>
                <button
                  type="button"
                  class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm disabled:opacity-50"
                  :disabled="isSaving || !editable || isOverLimit"
                  @click="previewEntry"
                >
                  Proceed to Preview
                </button>
              </div>
            </div>

            <p v-if="sections.length === 0" class="text-sm text-slate-500">
              No journal template sections are configured for your batch yet.
            </p>
          </div>

          <aside class="space-y-4">
            <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
              <div class="space-y-5">
                <div v-for="group in optionalSectionGroups" :key="group.title" class="space-y-2">
                  <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ group.title }}</p>
                  <div class="space-y-2">
                    <label
                      v-for="section in group.sections"
                      :key="section.key"
                      class="flex cursor-pointer items-start gap-3 rounded-md px-1 py-2"
                    >
                      <input
                        type="checkbox"
                        class="mt-0.5 h-5 w-5 rounded border-slate-300 text-blue-600"
                        :checked="!!enabledSections[section.key]"
                        :disabled="!editable"
                        @change="toggleSection(section, ($event.target as HTMLInputElement).checked)"
                      />
                      <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                          <p class="text-sm font-semibold text-slate-900">{{ section.label }}</p>
                          <span
                            v-if="section.sipp"
                            class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700"
                          >
                            SIPP
                          </span>
                        </div>
                        <p v-if="section.prompt" class="mt-1 text-xs text-slate-500">{{ section.prompt }}</p>
                      </div>
                    </label>
                  </div>
                </div>
              </div>

              <p v-if="optionalSections.length === 0" class="text-sm text-slate-500">
                No additional journal sections are configured for your batch yet.
              </p>
            </section>
          </aside>
        </div>

        <div class="max-w-3xl space-y-3">
          <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
          <p v-if="statusMessage" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>
          <p v-if="isOverLimit" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
            This entry exceeds the {{ wordLimit }}-word limit. Trim it down before saving.
          </p>
        </div>
      </div>
    </template>
  </section>
</template>

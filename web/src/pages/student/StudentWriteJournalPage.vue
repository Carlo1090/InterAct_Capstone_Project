<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import api from '@/lib/axios'
import type { JournalEntryDetail, JournalTemplateSection } from '@/types/api'

const route = useRoute()
const router = useRouter()

const entryDate = computed(() => (typeof route.query.date === 'string' ? route.query.date : new Date().toISOString().slice(0, 10)))

const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const statusMessage = ref('')
const status = ref<JournalEntryDetail['status']>('draft')
const editable = ref(true)
const sections = ref<JournalTemplateSection[]>([])
const wordLimit = ref(500)
const content = reactive<Record<string, string>>({})
const enabledSections = reactive<Record<string, boolean>>({})

const wordCount = computed(() =>
  Object.values(content).reduce((total, value) => total + (value.trim() ? value.trim().split(/\s+/).length : 0), 0),
)

const isOverLimit = computed(() => wordCount.value > wordLimit.value)

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

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  statusMessage.value = ''

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
        content[section.key] = existingValue
      } else {
        enabledSections[section.key] = existingValue.trim() !== ''
        if (enabledSections[section.key]) {
          content[section.key] = existingValue
        }
      }
    })
  } catch {
    errorMessage.value = 'Unable to load this journal entry.'
  } finally {
    isLoading.value = false
  }
}

const save = async (nextStatus: 'draft' | 'submitted') => {
  isSaving.value = true
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
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    errorMessage.value = data?.message ?? 'Unable to save this entry.'
  } finally {
    isSaving.value = false
  }
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
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Writing journal for <strong>{{ entryDate }}</strong>.
      <span v-if="!editable && !isLoading"> This date can no longer be edited (future date or outside your OJT range).</span>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>

    <div v-else class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
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

        <section v-for="section in sections" :key="section.key" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="text-sm font-bold text-slate-900">
                {{ section.label }}
                <span v-if="section.required" class="text-red-500">*</span>
                <span v-if="section.sipp" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">SIPP</span>
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

        <p v-if="sections.length === 0" class="text-sm text-slate-500">
          No journal template sections are configured for your batch yet.
        </p>
      </div>

      <aside class="space-y-5">
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Entry Summary</h2>
          <div class="mt-4 divide-y divide-slate-100 text-sm">
            <div class="flex justify-between py-2">
              <span class="text-slate-500">Word Count</span>
              <span class="font-mono font-semibold" :class="isOverLimit ? 'text-red-600' : ''">{{ wordCount }} / {{ wordLimit }}</span>
            </div>
            <div class="flex justify-between py-2"><span class="text-slate-500">Status</span><span class="font-semibold capitalize">{{ status }}</span></div>
          </div>
          <p v-if="isOverLimit" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
            This entry exceeds the {{ wordLimit }}-word limit. Trim it down before saving.
          </p>
        </section>

        <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
        <p v-if="statusMessage" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>

        <div class="flex justify-end gap-3">
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50"
            :disabled="isSaving || !editable || isOverLimit"
            @click="save('draft')"
          >
            Save Draft
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="isSaving || !editable || isOverLimit"
            @click="save('submitted')"
          >
            Submit Entry
          </button>
        </div>
      </aside>
    </div>
  </section>
</template>
